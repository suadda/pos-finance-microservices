<?php

namespace App\Services;

use App\Models\Posting;
use App\Support\Money;

/** Net figures from Finance's own ledger: sale minus reversal. */
class LedgerService
{
    /**
     * @return array{totals: array{cash: string, debit: string, qris: string}, trx_count: int, net_sales: string, tax: string, total: string}
     */
    public function dailyNet(?int $outletId, string $businessDate): array
    {
        $query = Posting::query()->whereDate('business_date', $businessDate);
        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $rows = $query
            ->selectRaw("payment_method,
                SUM(CASE WHEN entry_type = 'sale' THEN total_amount ELSE -total_amount END) AS total,
                SUM(CASE WHEN entry_type = 'sale' THEN net_sales_amount ELSE -net_sales_amount END) AS net_sales,
                SUM(CASE WHEN entry_type = 'sale' THEN tax_amount ELSE -tax_amount END) AS tax,
                SUM(CASE WHEN entry_type = 'sale' THEN 1 ELSE -1 END) AS trx_count")
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $totals = [];
        foreach (['cash', 'debit', 'qris'] as $method) {
            $totals[$method] = Money::of($rows[$method]->total ?? '0');
        }

        return [
            'totals' => $totals,
            'trx_count' => (int) $rows->sum('trx_count'),
            'net_sales' => Money::add(...$rows->map(fn ($r) => Money::of($r->net_sales))->values()->all()),
            'tax' => Money::add(...$rows->map(fn ($r) => Money::of($r->tax))->values()->all()),
            'total' => Money::add(...array_values($totals)),
        ];
    }
}
