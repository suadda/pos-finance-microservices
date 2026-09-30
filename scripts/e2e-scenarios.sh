#!/usr/bin/env bash
# End-to-end check of the mandatory test scenarios S1–S5 against a running stack.
#
#   ./scripts/e2e-scenarios.sh            # after `docker compose up -d --build` on a fresh database
#
# S1, S2, S3 and S5 each use a different demo outlet (BDG, GRT, SKB, TSM) so they can run on the same
# business day. Run on a fresh database (`docker compose down -v && docker compose up -d --build`).
#
# Environment overrides:
#   API             gateway base URL             (default http://localhost:8080/api)
#   FINANCE_DIRECT  Finance Service direct URL   (default http://localhost:8003) — S4/S5 internal checks
#   POS_DIRECT      POS Service direct URL       (default http://localhost:8002)
#   INTERNAL_API_KEY                              (default change-me-internal-service-key)
#   FINANCE_STOP / FINANCE_START  commands used by S2 to take Finance down / up
set -uo pipefail

API=${API:-http://localhost:8080/api}
FINANCE_DIRECT=${FINANCE_DIRECT:-http://localhost:8003}
POS_DIRECT=${POS_DIRECT:-http://localhost:8002}
INTERNAL_API_KEY=${INTERNAL_API_KEY:-change-me-internal-service-key}
FINANCE_STOP=${FINANCE_STOP:-docker compose stop finance-service}
FINANCE_START=${FINANCE_START:-docker compose start finance-service}
PASSWORD=password123
TODAY=$(TZ=Asia/Jakarta date +%F)

PASS=0
FAIL=0
STATUS=""
BODY=""
TMP_BODY=$(mktemp)
trap 'rm -f "$TMP_BODY"' EXIT

green() { printf '\033[32m%s\033[0m\n' "$*"; }
red() { printf '\033[31m%s\033[0m\n' "$*"; }

# call METHOD URL TOKEN [JSON] [EXTRA_HEADER]
call() {
  local method=$1 url=$2 token=${3:-} data=${4:-} extra=${5:-}
  local args=(-s --max-time 20 -o "$TMP_BODY" -w '%{http_code}' -X "$method" -H 'Accept: application/json' -H 'Content-Type: application/json')
  [[ -n $token ]] && args+=(-H "Authorization: Bearer $token")
  [[ -n $extra ]] && args+=(-H "$extra")
  [[ -n $data ]] && args+=(-d "$data")
  STATUS=$(curl "${args[@]}" "$url")
  BODY=$(cat "$TMP_BODY")
}

j() { jq -r "$1" <<<"$BODY"; }

check() { # check "description" actual expected
  if [[ "$2" == "$3" ]]; then
    PASS=$((PASS + 1)); green "  ✔ $1 ($2)"
  else
    FAIL=$((FAIL + 1)); red "  ✘ $1: expected [$3], got [$2]"; echo "    body: ${BODY:0:400}"
  fi
}

login() {
  call POST "$API/auth/login" "" "{\"email\":\"$1\",\"password\":\"$PASSWORD\"}"
  [[ $STATUS == 200 ]] || red "  login $1 failed: $STATUS ${BODY:0:200}" >&2
  j '.data.access_token'
}

product_id() { # sku -> id
  call GET "$API/pos/products?search=$1&per_page=5" "$2"
  j ".data[] | select(.sku == \"$1\") | .id"
}

create_trx() { # token product_id -> trx id
  call POST "$API/pos/transactions" "$1" "{\"items\":[{\"product_id\":$2,\"quantity\":1}]}"
  j '.data.id'
}

wait_up() { # url
  for _ in $(seq 1 60); do
    curl -s -o /dev/null "$1" && return 0
    sleep 1
  done
}

# Runs S1 steps on one outlet; FINANCE_DOWN_FOR_T2=1 stops Finance before T2 is paid (S2).
# Sets globals: KASIR STAFF SHIFT_ID T1 T2 T3 RECON_ID
run_s1_flow() {
  local code=$1 actual_cash=$2 finance_down=${3:-0}
  KASIR=$(login "kasir.$code@demo.test")
  local supervisor; supervisor=$(login "supervisor.$code@demo.test")
  STAFF=$(login "staff.finance@demo.test")

  local p100 p50 p200
  p100=$(product_id PKT-100K "$KASIR"); p50=$(product_id PKT-50K "$KASIR"); p200=$(product_id PKT-200K "$KASIR")

  call POST "$API/pos/shifts/open" "$KASIR" '{"opening_cash":200000}'
  check "open shift" "$STATUS" 201
  SHIFT_ID=$(j '.data.id')

  T1=$(create_trx "$KASIR" "$p100")
  call POST "$API/pos/transactions/$T1/pay" "$KASIR" '{"payment_method":"cash","paid_amount":150000}'
  check "T1 paid" "$(j .data.status)" paid
  check "T1 grand_total" "$(j .data.grand_total)" 111000.00
  check "T1 change" "$(j .data.change_amount)" 39000.00
  check "T1 synced" "$(j .data.finance_sync_status)" synced

  T2=$(create_trx "$KASIR" "$p50")
  if [[ $finance_down == 1 ]]; then
    echo "  … stopping Finance: $FINANCE_STOP"; eval "$FINANCE_STOP" >/dev/null 2>&1
  fi
  call POST "$API/pos/transactions/$T2/pay" "$KASIR" '{"payment_method":"qris","paid_amount":55500}'
  check "T2 paid (even if Finance is down)" "$STATUS/$(j .data.status)" 200/paid
  check "T2 grand_total" "$(j .data.grand_total)" 55500.00
  check "T2 sync status" "$(j .data.finance_sync_status)" "$([[ $finance_down == 1 ]] && echo failed || echo synced)"

  T3=$(create_trx "$KASIR" "$p200")
  call POST "$API/pos/transactions/$T3/pay" "$KASIR" '{"payment_method":"debit","paid_amount":222000}'
  check "T3 grand_total" "$(j .data.grand_total)" 222000.00
  call POST "$API/pos/transactions/$T3/void" "$supervisor" '{"reason":"Salah input produk"}'
  check "T3 void by supervisor" "$STATUS/$(j .data.status)" 200/void

  call POST "$API/pos/shifts/$SHIFT_ID/close" "$KASIR" "{\"actual_cash\":$actual_cash}"
  check "close shift" "$STATUS" 200
  check "expected_cash" "$(j .data.expected_cash)" 311000.00
  CLOSE_VARIANCE=$(j .data.shift.cash_variance)
  CLOSE_UNSYNCED=$(j .data.unsynced_count)

  if [[ $finance_down == 1 ]]; then
    echo "  … starting Finance: $FINANCE_START"; eval "$FINANCE_START" >/dev/null 2>&1
    wait_up "$FINANCE_DIRECT/health"
  fi

  local outlet_id; outlet_id=$(curl -s -H "Authorization: Bearer $STAFF" "$API/pos/outlets" | jq -r ".data[] | select(.code == \"${code^^}\") | .id")
  OUTLET_ID=$outlet_id
  call POST "$API/finance/reconciliations/run" "$STAFF" "{\"outlet_id\":$outlet_id,\"business_date\":\"$TODAY\"}"
  RECON_ID=$(j .data.id)
}

echo "Waiting for the stack at $API …"
for _ in $(seq 1 90); do
  [[ $(curl -s -o /dev/null -w '%{http_code}' --max-time 5 -X POST -H 'Content-Type: application/json' -d '{}' "$API/auth/login") == 422 ]] &&
    [[ $(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$API/pos/outlets") == 401 ]] &&
    [[ $(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$API/finance/postings") == 401 ]] && break
  sleep 2
done

echo "== S1 happy path (BDG)"
run_s1_flow bdg 311000
check "cash_variance" "$CLOSE_VARIANCE" 0.00
check "EOD status" "$(j .data.status)" matched
check "EOD pos vs fin cash" "$(j .data.pos_cash)/$(j .data.fin_cash)" 111000.00/111000.00
check "EOD pos vs fin qris" "$(j .data.pos_qris)/$(j .data.fin_qris)" 55500.00/55500.00
check "EOD pos vs fin debit" "$(j .data.pos_debit)/$(j .data.fin_debit)" 0.00/0.00
check "EOD trx count" "$(j .data.pos_trx_count)/$(j .data.fin_trx_count)" 2/2
T1_NUMBER=$(curl -s -H "Authorization: Bearer $KASIR" "$API/pos/transactions/$T1" | jq -r .data.trx_number)
call GET "$API/finance/postings?search=$T1_NUMBER" "$STAFF"
call GET "$API/finance/postings/$(j '.data[0].id')" "$STAFF"
check "T1 journal lines" "$(j '[.data.lines[] | "\(.account_code):\(.debit)/\(.credit)"] | join(",")')" "1101:111000.00/0.00,4101:0.00/100000.00,2101:0.00/11000.00"
check "T1 journal balanced" "$(j .data.is_balanced)" true

echo "== S2 Finance unavailable (GRT)"
run_s1_flow grt 311000 1
check "close unsynced_count" "$CLOSE_UNSYNCED" 1
check "first EOD mismatch" "$(j .data.status)" mismatch
check "AMOUNT_DIFF qris" "$(j '.data.mismatch_reasons[] | select(.type=="AMOUNT_DIFF") | "\(.method):\(.diff)"')" "qris:55500.00"
check "COUNT_DIFF" "$(j '.data.mismatch_reasons[] | select(.type=="COUNT_DIFF") | .diff')" 1
FIRST_ID=$RECON_ID
call POST "$API/pos/transactions/$T2/resync-finance" "$KASIR"
check "resync T2" "$(j .data.finance_sync_status)" synced
call POST "$API/finance/reconciliations/run" "$STAFF" "{\"outlet_id\":$OUTLET_ID,\"business_date\":\"$TODAY\"}"
check "re-run EOD matched" "$(j .data.status)" matched
check "same reconciliation row" "$(j .data.id)" "$FIRST_ID"

echo "== S3 cash variance (SKB)"
run_s1_flow skb 306000
check "cash_variance" "$CLOSE_VARIANCE" -5000.00
check "EOD mismatch" "$(j .data.status)" mismatch
check "CASH_VARIANCE reason" "$(j '.data.mismatch_reasons[] | select(.type=="CASH_VARIANCE") | .amount')" -5000.00
MANAGER=$(login manager.finance@demo.test)
call PATCH "$API/finance/reconciliations/$RECON_ID/resolve" "$STAFF" '{"resolution_note":"Selisih kas"}'
check "staff_finance cannot resolve" "$STATUS" 403
call PATCH "$API/finance/reconciliations/$RECON_ID/resolve" "$MANAGER" '{}'
check "resolve without note rejected" "$STATUS" 422
call PATCH "$API/finance/reconciliations/$RECON_ID/resolve" "$MANAGER" '{"resolution_note":"Kasir kurang setor Rp5.000, sudah dipotong dari insentif"}'
check "resolve with note" "$STATUS/$(j .data.status)" 200/resolved
call POST "$API/finance/reconciliations/run" "$STAFF" "{\"outlet_id\":$OUTLET_ID,\"business_date\":\"$TODAY\"}"
check "re-run resolved EOD" "$STATUS" 409

echo "== S4 idempotent posting (direct to Finance with service key)"
KEY="TRX/E2E/$(date +%s)"
PAYLOAD="{\"idempotency_key\":\"$KEY:sale\",\"trx_number\":\"$KEY\",\"entry_type\":\"sale\",\"outlet_id\":3,\"outlet_code\":\"SKB\",\"shift_id\":999,\"business_date\":\"2020-01-01\",\"payment_method\":\"cash\",\"net_sales_amount\":\"1000.00\",\"tax_amount\":\"110.00\",\"total_amount\":\"1110.00\"}"
call POST "$FINANCE_DIRECT/internal/postings" "" "$PAYLOAD" "X-Service-Key: $INTERNAL_API_KEY"
check "first posting" "$STATUS" 201
call POST "$FINANCE_DIRECT/internal/postings" "" "$PAYLOAD" "X-Service-Key: $INTERNAL_API_KEY"
check "duplicate posting" "$STATUS" 200
call GET "$API/finance/postings?search=$KEY" "$STAFF"
check "exactly one posting" "$(j .meta.total)" 1
call GET "$API/finance/postings/$(j '.data[0].id')" "$STAFF"
check "one set of journal lines" "$(j '.data.lines | length')" 3
REV="{\"idempotency_key\":\"TRX/NOPE/1:reversal\",\"trx_number\":\"TRX/NOPE/1\",\"entry_type\":\"reversal\",\"outlet_id\":3,\"outlet_code\":\"SKB\",\"shift_id\":1,\"business_date\":\"2020-01-01\",\"payment_method\":\"cash\",\"net_sales_amount\":\"1\",\"tax_amount\":\"0\",\"total_amount\":\"1\"}"
call POST "$FINANCE_DIRECT/internal/postings" "" "$REV" "X-Service-Key: $INTERNAL_API_KEY"
check "reversal without sale" "$STATUS" 422

echo "== S5 guards (TSM)"
KASIR=$(login kasir.tsm@demo.test)
SUPERVISOR=$(login supervisor.tsm@demo.test)
call POST "$API/pos/shifts/open" "$KASIR" '{"opening_cash":100000}'
SHIFT_ID=$(j .data.id)
call POST "$API/pos/shifts/open" "$KASIR" '{"opening_cash":100000}'
check "second open shift" "$STATUS" 409
KOPI=$(product_id KOP-001 "$KASIR")
# 10 simultaneous transaction creations must yield 10 distinct numbers.
NUMBERS=$(seq 1 10 | xargs -P 10 -I{} curl -s --max-time 20 -X POST "$API/pos/transactions" \
  -H "Authorization: Bearer $KASIR" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"items\":[{\"product_id\":$KOPI,\"quantity\":1}]}" | jq -r '.data.trx_number')
check "10 concurrent trx numbers are unique" "$(sort -u <<<"$NUMBERS" | grep -c '^TRX/TSM/')" 10
for id in $(curl -s -H "Authorization: Bearer $KASIR" "$API/pos/transactions?status=pending&per_page=50" | jq -r '.data[].id'); do
  call POST "$API/pos/transactions/$id/pay" "$KASIR" '{"payment_method":"qris","paid_amount":19980}'
done
call POST "$API/pos/transactions" "$KASIR" "{\"items\":[{\"product_id\":$KOPI,\"quantity\":1}],\"discount_amount\":999999}"
check "discount above subtotal" "$STATUS" 422
call POST "$API/pos/transactions" "$KASIR" "{\"items\":[{\"product_id\":$(product_id OLD-001 "$KASIR"),\"quantity\":1}]}"
check "inactive product rejected" "$STATUS" 422
TRX=$(create_trx "$KASIR" "$KOPI")
call POST "$API/pos/transactions/$TRX/pay" "$KASIR" '{"payment_method":"debit","paid_amount":20000}'
check "debit must be exact" "$STATUS" 422
call POST "$API/pos/transactions/$TRX/void" "$SUPERVISOR" '{"reason":"pending"}'
check "void pending trx" "$STATUS" 409
call POST "$API/pos/shifts/$SHIFT_ID/close" "$KASIR" '{"actual_cash":100000}'
check "close with pending trx" "$STATUS" 409
call POST "$API/finance/reconciliations/run" "$STAFF" "{\"outlet_id\":4,\"business_date\":\"$TODAY\"}"
check "EOD with open shift" "$STATUS" 409
call POST "$API/pos/transactions/$TRX/pay" "$KASIR" '{"payment_method":"cash","paid_amount":19980}'
call POST "$API/pos/shifts/$SHIFT_ID/close" "$KASIR" '{"actual_cash":119980}'
check "close after paying" "$STATUS" 200
call POST "$API/pos/transactions/$TRX/void" "$SUPERVISOR" '{"reason":"Coba void setelah tutup shift"}'
check "void after shift closed" "$STATUS" 409
call POST "$API/finance/reconciliations/run" "$STAFF" "{\"outlet_id\":4,\"business_date\":\"2000-01-01\"}"
check "EOD without shifts" "$STATUS" 422
call GET "$API/pos/internal/eod-summary?outlet_id=1&business_date=$TODAY" "" "" "X-Service-Key: $INTERNAL_API_KEY"
check "POS /internal via gateway" "$STATUS" 404
call POST "$API/finance/internal/postings" "" "$PAYLOAD" "X-Service-Key: $INTERNAL_API_KEY"
check "Finance /internal via gateway" "$STATUS" 404
call GET "$POS_DIRECT/internal/eod-summary?outlet_id=1&business_date=$TODAY"
check "POS /internal without key" "$STATUS" 403
call POST "$FINANCE_DIRECT/internal/postings" "" "$PAYLOAD" "X-Service-Key: wrong"
check "Finance /internal with wrong key" "$STATUS" 403
call GET "$API/pos/transactions?per_page=1" "$STAFF"
check "finance role cannot list POS transactions" "$STATUS" 403
call GET "$API/finance/postings" "$KASIR"
check "kasir cannot read postings" "$STATUS" 403
call GET "$API/pos/transactions?per_page=100" "$(login kasir.bdg@demo.test)"
check "kasir sees only own transactions" "$(j '[.data[].outlet_code] | unique | join(",")')" BDG

echo "== Auth: refresh rotation, logout blacklist, deactivation"
call POST "$API/auth/login" "" "{\"email\":\"superadmin@demo.test\",\"password\":\"$PASSWORD\"}"
ADMIN=$(j .data.access_token); REFRESH=$(j .data.refresh_token)
call POST "$API/auth/login" "" '{"email":"superadmin@demo.test","password":"wrong"}'
check "wrong password" "$STATUS" 401
call POST "$API/auth/refresh" "" "{\"refresh_token\":\"$REFRESH\"}"
check "refresh" "$STATUS" 200
NEW_ACCESS=$(j .data.access_token); NEW_REFRESH=$(j .data.refresh_token)
call POST "$API/auth/refresh" "" "{\"refresh_token\":\"$REFRESH\"}"
check "old refresh token is revoked after rotation" "$STATUS/$(j .error.code)" 401/TOKEN_REVOKED
call POST "$API/auth/refresh" "" "{\"refresh_token\":\"$NEW_ACCESS\"}"
check "access token cannot be used as refresh token" "$STATUS" 401
call POST "$API/auth/logout" "$NEW_ACCESS" "{\"refresh_token\":\"$NEW_REFRESH\"}"
check "logout" "$STATUS" 200
call POST "$API/auth/refresh" "" "{\"refresh_token\":\"$NEW_REFRESH\"}"
check "refresh after logout" "$STATUS" 401
call GET "$API/auth/me" "$NEW_ACCESS"
check "access token after logout" "$STATUS" 401
call GET "$API/auth/users" "$STAFF"
check "non-superadmin cannot manage users" "$STATUS" 403
call POST "$API/auth/users" "$ADMIN" '{"name":"Kasir Tanpa Outlet","email":"no-outlet@demo.test","password":"password123","role":"kasir"}'
check "kasir requires outlet_id" "$STATUS" 422
EMAIL="temp.$(date +%s)@demo.test"
call POST "$API/auth/users" "$ADMIN" "{\"name\":\"Kasir Sementara\",\"email\":\"$EMAIL\",\"password\":\"password123\",\"role\":\"kasir\",\"outlet_id\":3}"
check "create user" "$STATUS" 201
TEMP_ID=$(j .data.id)
TEMP_TOKEN=$(login "$EMAIL")
call GET "$API/pos/shifts/current" "$TEMP_TOKEN"
check "new kasir can use POS" "$STATUS" 200
call PUT "$API/auth/users/$TEMP_ID" "$ADMIN" '{"role":"staff_finance"}'
check "role change clears outlet_id" "$(j .data.role)/$(j .data.outlet_id)" staff_finance/null
call DELETE "$API/auth/users/$TEMP_ID" "$ADMIN"
check "deactivate user" "$(j .data.is_active)" false
call GET "$API/pos/shifts/current" "$TEMP_TOKEN"
check "deactivated user rejected by POS" "$STATUS" 401
call POST "$API/auth/login" "" "{\"email\":\"$EMAIL\",\"password\":\"password123\"}"
check "deactivated user cannot login" "$STATUS/$(j .error.code)" 401/ACCOUNT_INACTIVE

echo
echo "Passed: $PASS  Failed: $FAIL"
[[ $FAIL -eq 0 ]]
