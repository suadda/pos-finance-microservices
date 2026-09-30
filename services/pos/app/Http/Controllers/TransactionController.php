<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\TransactionService;
use App\Support\AccessScope;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransactionController
{
    private const MONEY_RULE = ['numeric', 'min:0', 'max:9999999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'];

    public function __construct(private readonly TransactionService $transactions) {}

    /** GET /transactions — filters: status, outlet_id, shift_id, payment_method, finance_sync_status, date_from, date_to, search */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'paid', 'void'])],
            'outlet_id' => ['nullable', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(Transaction::METHODS)],
            'finance_sync_status' => ['nullable', Rule::in(['none', 'pending', 'synced', 'failed'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:50'],
        ]);

        $query = AccessScope::apply(Transaction::query(), $request->attributes->get('auth_user'))->latest('id');
        foreach (['status', 'outlet_id', 'shift_id', 'payment_method', 'finance_sync_status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['date_from'])) {
            $query->whereDate('business_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('business_date', '<=', $filters['date_to']);
        }
        if (isset($filters['search'])) {
            $query->where('trx_number', 'like', "%{$filters['search']}%");
        }

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage()));
    }

    /** GET /transactions/{transaction} — with items and the shift status (for "can void" in the UI). */
    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        AccessScope::ensureCanSee($request->attributes->get('auth_user'), $transaction);

        return ApiResponse::ok($this->present($transaction->load('items', 'shift')));
    }

    /** POST /transactions {items: [{product_id, quantity}], discount_amount?} — prices are never accepted from the client. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'discount_amount' => ['nullable', ...self::MONEY_RULE],
        ]);

        $trx = $this->transactions->create(
            $request->attributes->get('auth_user'),
            $data['items'],
            Money::of($data['discount_amount'] ?? '0'),
        );

        return ApiResponse::created($trx);
    }

    /** POST /transactions/{transaction}/pay {payment_method, paid_amount} */
    public function pay(Request $request, Transaction $transaction): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', Rule::in(Transaction::METHODS)],
            'paid_amount' => ['required', ...self::MONEY_RULE],
        ]);

        $trx = $this->transactions->pay($request->attributes->get('auth_user'), $transaction, $data['payment_method'], Money::of($data['paid_amount']));

        return ApiResponse::ok($trx);
    }

    /** POST /transactions/{transaction}/void {reason} */
    public function void(Request $request, Transaction $transaction): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return ApiResponse::ok($this->transactions->void($request->attributes->get('auth_user'), $transaction, trim($data['reason'])));
    }

    /** POST /transactions/{transaction}/resync-finance */
    public function resync(Request $request, Transaction $transaction): JsonResponse
    {
        return ApiResponse::ok($this->transactions->resync($request->attributes->get('auth_user'), $transaction));
    }

    private function present(Transaction $trx): array
    {
        $data = $trx->toArray();
        $data['shift_status'] = $trx->shift?->status;
        unset($data['shift']);

        return $data;
    }
}
