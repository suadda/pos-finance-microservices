<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\Transaction;
use App\Support\AccessScope;
use App\Support\AuthUser;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    public function __construct(private readonly FinanceSyncService $sync) {}

    public function current(AuthUser $user): ?Shift
    {
        return Shift::where('cashier_id', $user->id)->where('status', Shift::OPEN)->first();
    }

    /** Opens a shift for the cashier's outlet (superadmin passes outlet_id explicitly). */
    public function open(AuthUser $user, string $openingCash, ?int $outletId): Shift
    {
        $outletId = $user->outletId ?? $outletId;
        if (! $outletId) {
            throw ApiException::unprocessable('OUTLET_REQUIRED', 'Akun tidak terikat outlet; kirim outlet_id');
        }
        $outlet = Outlet::where('is_active', true)->find($outletId);
        if (! $outlet) {
            throw ApiException::unprocessable('OUTLET_NOT_FOUND', 'Outlet tidak ditemukan atau nonaktif');
        }
        if ($this->current($user)) {
            throw ApiException::conflict('SHIFT_ALREADY_OPEN', 'Anda masih memiliki shift yang terbuka');
        }

        try {
            return Shift::create([
                'outlet_id' => $outlet->id,
                'cashier_id' => $user->id,
                'business_date' => now()->toDateString(), // Asia/Jakarta (app timezone)
                'opened_at' => now(),
                'opening_cash' => $openingCash,
                'status' => Shift::OPEN,
            ]);
        } catch (QueryException $e) {
            // Unique index on open_cashier_guard: a concurrent request opened a shift first.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ApiException::conflict('SHIFT_ALREADY_OPEN', 'Anda masih memiliki shift yang terbuka');
            }
            throw $e;
        }
    }

    /**
     * Real-time shift figures. expected_cash = opening_cash + Σ grand_total of paid cash transactions
     * (voided transactions are excluded because their status is no longer "paid").
     */
    public function summary(Shift $shift): array
    {
        $byStatus = Transaction::where('shift_id', $shift->id)
            ->selectRaw('status, COUNT(*) AS cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $byMethod = Transaction::where('shift_id', $shift->id)
            ->where('status', Transaction::PAID)
            ->selectRaw('payment_method, COUNT(*) AS cnt, COALESCE(SUM(grand_total), 0) AS total')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $totals = [];
        foreach (Transaction::METHODS as $method) {
            $totals[$method] = [
                'count' => (int) ($byMethod[$method]->cnt ?? 0),
                'total' => Money::of($byMethod[$method]->total ?? '0'),
            ];
        }

        $unsynced = Transaction::where('shift_id', $shift->id)->unsyncedAffectingLedger()->count();
        $unsyncedTotal = Transaction::where('shift_id', $shift->id)->unsynced()->count();

        return [
            'shift' => $shift,
            'trx_count' => (int) ($byStatus[Transaction::PAID] ?? 0),
            'pending_count' => (int) ($byStatus[Transaction::PENDING] ?? 0),
            'void_count' => (int) ($byStatus[Transaction::VOID] ?? 0),
            'totals_by_method' => $totals,
            'total_sales' => Money::add(...array_column($totals, 'total')),
            'expected_cash' => $shift->isOpen()
                ? Money::add($shift->opening_cash, $totals['cash']['total'])
                : $shift->expected_cash,
            // Transactions whose missing Finance events change the ledger (see Transaction::scopeUnsyncedAffectingLedger).
            'unsynced_count' => $unsynced,
            // Every transaction not fully acknowledged by Finance (includes voids that net to zero).
            'unsynced_total' => $unsyncedTotal,
        ];
    }

    /**
     * Closes a shift:
     *  1. 409 if pending transactions exist;
     *  2. one retry for every not-yet-synced transaction (Finance being down does NOT block closing);
     *  3. compute expected_cash / cash_variance under a row lock;
     *  4. report unsynced_count as a warning.
     */
    public function close(AuthUser $user, Shift $shift, string $actualCash): array
    {
        AccessScope::ensureOwner($user, $shift);
        $this->assertClosable($shift);

        // HTTP calls stay outside the DB transaction.
        $this->sync->syncMany(
            Transaction::where('shift_id', $shift->id)->unsynced()->orderBy('id')->get(),
        );

        $shift = DB::transaction(function () use ($shift, $actualCash) {
            $shift = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $this->assertClosable($shift);

            $cashSales = Transaction::where('shift_id', $shift->id)
                ->where('status', Transaction::PAID)
                ->where('payment_method', 'cash')
                ->sum('grand_total');
            $expected = Money::add($shift->opening_cash, Money::of($cashSales));

            $shift->update([
                'status' => Shift::CLOSED,
                'closed_at' => now(),
                'expected_cash' => $expected,
                'actual_cash' => $actualCash,
                'cash_variance' => Money::sub($actualCash, $expected),
            ]);

            return $shift;
        });

        $summary = $this->summary($shift);
        if ($summary['unsynced_total'] > 0) {
            $summary['warning'] = "{$summary['unsynced_count']} transaksi belum tersinkron ke Finance (berpengaruh ke ledger), "
                ."{$summary['unsynced_total']} total belum tersinkron. Lakukan resync sebelum rekonsiliasi.";
        }

        return $summary;
    }

    private function assertClosable(Shift $shift): void
    {
        if (! $shift->isOpen()) {
            throw ApiException::conflict('SHIFT_CLOSED', 'Shift sudah ditutup');
        }
        $pending = Transaction::where('shift_id', $shift->id)->where('status', Transaction::PENDING)->count();
        if ($pending > 0) {
            throw ApiException::conflict('PENDING_TRANSACTIONS', "Masih ada {$pending} transaksi pending pada shift ini", [
                'pending_count' => $pending,
            ]);
        }
    }
}
