<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Transaction;
use App\Support\AccessScope;
use App\Support\AuthUser;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    public function __construct(
        private readonly TransactionCalculator $calculator,
        private readonly TrxNumberGenerator $numbers,
        private readonly FinanceSyncService $sync,
    ) {}

    /**
     * Creates a pending transaction in the cashier's open shift. Prices always come from `products`.
     *
     * @param  list<array{product_id: int, quantity: int}>  $items
     */
    public function create(AuthUser $user, array $items, string $discount): Transaction
    {
        // Merge duplicate product lines so each product appears once.
        $quantities = [];
        foreach ($items as $item) {
            $quantities[(int) $item['product_id']] = ($quantities[(int) $item['product_id']] ?? 0) + (int) $item['quantity'];
        }

        $trx = DB::transaction(function () use ($user, $quantities, $discount) {
            // Lock the shift so it cannot be closed while this transaction is being created.
            $shift = Shift::with('outlet')
                ->where('cashier_id', $user->id)
                ->where('status', Shift::OPEN)
                ->lockForUpdate()
                ->first();
            if (! $shift) {
                throw ApiException::conflict('NO_OPEN_SHIFT', 'Buka shift terlebih dahulu sebelum membuat transaksi');
            }

            $products = Product::whereIn('id', array_keys($quantities))->get()->keyBy('id');
            $lines = [];
            foreach ($quantities as $productId => $qty) {
                $product = $products->get($productId);
                if (! $product) {
                    throw ApiException::unprocessable('PRODUCT_NOT_FOUND', "Produk #{$productId} tidak ditemukan");
                }
                if (! $product->is_active) {
                    throw ApiException::unprocessable('PRODUCT_INACTIVE', "Produk {$product->name} nonaktif dan tidak dapat dijual");
                }
                $lines[] = ['product' => $product, 'unit_price' => $product->price, 'quantity' => $qty];
            }

            $totals = $this->calculator->totals($lines, $discount);

            $trx = Transaction::create([
                'trx_number' => $this->numbers->next($shift->outlet_id, $shift->outlet->code, $shift->business_date),
                'outlet_id' => $shift->outlet_id,
                'outlet_code' => $shift->outlet->code,
                'shift_id' => $shift->id,
                'cashier_id' => $user->id,
                'business_date' => $shift->business_date->format('Y-m-d'),
                'status' => Transaction::PENDING,
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'tax_amount' => $totals['tax_amount'],
                'grand_total' => $totals['grand_total'],
            ]);

            foreach ($lines as $i => $line) {
                $trx->items()->create([
                    'product_id' => $line['product']->id,
                    'sku' => $line['product']->sku,
                    'product_name' => $line['product']->name,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $totals['lines'][$i],
                ]);
            }

            return $trx;
        });

        return $trx->load('items');
    }

    /** pending -> paid, then posts the sale to Finance (a Finance failure never fails the payment). */
    public function pay(AuthUser $user, Transaction $trx, string $method, string $paidAmount): Transaction
    {
        AccessScope::ensureOwner($user, $trx);

        $trx = DB::transaction(function () use ($trx, $method, $paidAmount) {
            $trx = Transaction::whereKey($trx->id)->lockForUpdate()->firstOrFail();
            if ($trx->status !== Transaction::PENDING) {
                throw ApiException::conflict('INVALID_STATUS', "Transaksi berstatus {$trx->status}, hanya transaksi pending yang dapat dibayar");
            }
            if (! Shift::whereKey($trx->shift_id)->where('status', Shift::OPEN)->exists()) {
                throw ApiException::conflict('SHIFT_CLOSED', 'Shift transaksi ini sudah ditutup');
            }

            $change = $this->calculator->change($trx->grand_total, $method, $paidAmount);
            $trx->update([
                'status' => Transaction::PAID,
                'payment_method' => $method,
                'paid_amount' => $paidAmount,
                'change_amount' => $change,
                'paid_at' => now(),
                'finance_sync_status' => Transaction::SYNC_PENDING,
            ]);

            return $trx;
        });

        $this->sync->sync($trx); // after commit; records synced / failed on the row

        return $trx->fresh('items');
    }

    /** paid -> void (only while the shift is still open), then posts the reversal to Finance. */
    public function void(AuthUser $user, Transaction $trx, string $reason): Transaction
    {
        AccessScope::ensureCanSee($user, $trx);

        $trx = DB::transaction(function () use ($user, $trx, $reason) {
            $trx = Transaction::whereKey($trx->id)->lockForUpdate()->firstOrFail();
            if ($trx->status !== Transaction::PAID) {
                throw ApiException::conflict('INVALID_STATUS', "Transaksi berstatus {$trx->status}, hanya transaksi paid yang dapat di-void");
            }
            $shiftOpen = Shift::whereKey($trx->shift_id)->where('status', Shift::OPEN)->lockForUpdate()->exists();
            if (! $shiftOpen) {
                throw ApiException::conflict('SHIFT_CLOSED', 'Transaksi dari shift yang sudah ditutup tidak dapat di-void');
            }

            $trx->update([
                'status' => Transaction::VOID,
                'void_reason' => $reason,
                'voided_by' => $user->id,
                'voided_at' => now(),
                'finance_sync_status' => Transaction::SYNC_PENDING,
            ]);

            return $trx;
        });

        $this->sync->sync($trx);

        return $trx->fresh('items');
    }

    /** Re-sends the events of a paid/void transaction. Idempotent: already-synced is a no-op. */
    public function resync(AuthUser $user, Transaction $trx): Transaction
    {
        AccessScope::ensureCanSee($user, $trx);

        if ($trx->status === Transaction::PENDING) {
            throw ApiException::conflict('NOTHING_TO_SYNC', 'Transaksi pending belum memiliki event untuk dikirim ke Finance');
        }
        if ($trx->finance_sync_status !== Transaction::SYNC_SYNCED) {
            $this->sync->sync($trx);
        }

        return $trx->fresh('items');
    }

    /** Aggregate figures for the POS dashboard (already scoped by AccessScope). */
    public static function aggregate($scopedQuery): array
    {
        $rows = (clone $scopedQuery)
            ->selectRaw('status, payment_method, COUNT(*) AS cnt, COALESCE(SUM(grand_total), 0) AS total')
            ->groupBy('status', 'payment_method')
            ->get();

        $byMethod = array_fill_keys(Transaction::METHODS, ['count' => 0, 'total' => '0.00']);
        $counts = ['paid' => 0, 'pending' => 0, 'void' => 0];
        foreach ($rows as $row) {
            $counts[$row->status] += (int) $row->cnt;
            if ($row->status === Transaction::PAID && $row->payment_method) {
                $byMethod[$row->payment_method] = ['count' => (int) $row->cnt, 'total' => Money::of($row->total)];
            }
        }

        return [
            'trx_count' => $counts['paid'],
            'pending_count' => $counts['pending'],
            'void_count' => $counts['void'],
            'total_sales' => Money::add(...array_column($byMethod, 'total')),
            'by_method' => $byMethod,
            'unsynced_count' => (clone $scopedQuery)->unsynced()->count(),
        ];
    }
}
