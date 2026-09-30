<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\EodReconciliation;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function __construct(
        private readonly PosClient $pos,
        private readonly LedgerService $ledger,
        private readonly ReconciliationComparator $comparator,
    ) {}

    /**
     * Runs (or re-runs) End of Day for one outlet/date. A mismatch row is updated in place;
     * matched / resolved rows are final (409).
     */
    public function run(AuthUser $user, int $outletId, string $businessDate): EodReconciliation
    {
        $this->assertNotFinal(EodReconciliation::where(['outlet_id' => $outletId])->whereDate('business_date', $businessDate)->first());

        $pos = $this->pos->eodSummary($outletId, $businessDate);
        if ((int) $pos['shift_count'] === 0) {
            throw ApiException::unprocessable('NO_SHIFTS', 'Tidak ada shift pada outlet dan tanggal tersebut');
        }
        if (! $pos['all_shifts_closed']) {
            throw ApiException::conflict('SHIFTS_STILL_OPEN', "Masih ada {$pos['open_shift_count']} shift terbuka; End of Day belum boleh dijalankan");
        }

        $finance = $this->ledger->dailyNet($outletId, $businessDate);
        $result = $this->comparator->compare($pos, $finance);

        return DB::transaction(function () use ($user, $outletId, $businessDate, $pos, $finance, $result) {
            // Ensure the row exists, then lock it: concurrent runs for the same outlet/day are serialised.
            DB::table('eod_reconciliations')->insertOrIgnore([
                'outlet_id' => $outletId,
                'outlet_code' => $pos['outlet_code'],
                'business_date' => $businessDate,
                'status' => EodReconciliation::PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = EodReconciliation::where('outlet_id', $outletId)
                ->whereDate('business_date', $businessDate)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertNotFinal($row);

            $row->update([
                'outlet_code' => $pos['outlet_code'],
                'status' => $result['status'],
                'pos_cash' => $pos['totals']['cash'],
                'pos_debit' => $pos['totals']['debit'],
                'pos_qris' => $pos['totals']['qris'],
                'fin_cash' => $finance['totals']['cash'],
                'fin_debit' => $finance['totals']['debit'],
                'fin_qris' => $finance['totals']['qris'],
                'pos_trx_count' => $pos['trx_count'],
                'fin_trx_count' => $finance['trx_count'],
                'cash_variance' => $pos['cash_variance'],
                'pos_void_count' => $pos['void_count'],
                'pos_unsynced_count' => $pos['unsynced_count'],
                'mismatch_reasons' => $result['reasons'] ?: null,
                'run_by' => $user->id,
                'run_at' => now(),
            ]);

            return $row;
        });
    }

    /** mismatch -> resolved (manager_finance / superadmin), with a mandatory note. */
    public function resolve(AuthUser $user, EodReconciliation $row, string $note): EodReconciliation
    {
        return DB::transaction(function () use ($user, $row, $note) {
            $row = EodReconciliation::whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== EodReconciliation::MISMATCH) {
                throw ApiException::conflict('INVALID_STATUS', "Hanya rekonsiliasi berstatus mismatch yang dapat di-resolve (status: {$row->status})");
            }
            $row->update([
                'status' => EodReconciliation::RESOLVED,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ]);

            return $row;
        });
    }

    private function assertNotFinal(?EodReconciliation $row): void
    {
        if ($row && in_array($row->status, EodReconciliation::FINAL, true)) {
            throw ApiException::conflict('RECONCILIATION_FINAL', "Rekonsiliasi sudah berstatus {$row->status} dan bersifat final", [
                'reconciliation_id' => $row->id,
            ]);
        }
    }
}
