<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\Transaction;
use App\Support\Money;

/** End-of-Day figures for one outlet and business date, consumed by Finance reconciliation. */
class EodSummaryService
{
    public function summary(int $outletId, string $businessDate): array
    {
        $outlet = Outlet::find($outletId);
        if (! $outlet) {
            throw ApiException::notFound('Outlet tidak ditemukan');
        }

        $shifts = Shift::where('outlet_id', $outletId)->whereDate('business_date', $businessDate)->get();
        $openShifts = $shifts->where('status', Shift::OPEN)->count();

        $trx = Transaction::where('outlet_id', $outletId)->whereDate('business_date', $businessDate);

        // Net = paid and not voided (a voided transaction has status "void", so it is simply excluded).
        $paid = (clone $trx)->where('status', Transaction::PAID)
            ->selectRaw('payment_method, COUNT(*) AS cnt, COALESCE(SUM(grand_total), 0) AS total')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $totals = [];
        foreach (Transaction::METHODS as $method) {
            $totals[$method] = Money::of($paid[$method]->total ?? '0');
        }

        return [
            'outlet_id' => $outlet->id,
            'outlet_code' => $outlet->code,
            'business_date' => $businessDate,
            'shift_count' => $shifts->count(),
            'open_shift_count' => $openShifts,
            'all_shifts_closed' => $shifts->isNotEmpty() && $openShifts === 0,
            'trx_count' => (int) $paid->sum('cnt'),
            'totals' => $totals,
            'void_count' => (clone $trx)->where('status', Transaction::VOID)->count(),
            'cash_variance' => Money::add(...$shifts->where('status', Shift::CLOSED)->pluck('cash_variance')->map(fn ($v) => Money::of($v))->all()),
            'unsynced_count' => (clone $trx)->unsyncedAffectingLedger()->count(),
            'unsynced_total' => (clone $trx)->unsynced()->count(),
        ];
    }
}
