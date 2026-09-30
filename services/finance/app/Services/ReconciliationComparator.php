<?php

namespace App\Services;

use App\Support\Money;

/**
 * Pure EOD comparison (no I/O). Difference = POS - Finance.
 * matched  <=> every per-method difference is 0, the transaction count difference is 0 and cash_variance is 0.
 */
final class ReconciliationComparator
{
    /**
     * @param  array{totals: array<string, string>, trx_count: int, cash_variance: string, unsynced_count?: int}  $pos
     * @param  array{totals: array<string, string>, trx_count: int}  $finance
     * @return array{status: string, reasons: list<array<string, mixed>>}
     */
    public function compare(array $pos, array $finance): array
    {
        $reasons = [];

        foreach (['cash', 'debit', 'qris'] as $method) {
            $p = Money::of($pos['totals'][$method] ?? '0');
            $f = Money::of($finance['totals'][$method] ?? '0');
            $diff = Money::sub($p, $f);
            if (! Money::isZero($diff)) {
                $reasons[] = ['type' => 'AMOUNT_DIFF', 'method' => $method, 'pos' => $p, 'finance' => $f, 'diff' => $diff];
            }
        }

        $countDiff = (int) $pos['trx_count'] - (int) $finance['trx_count'];
        if ($countDiff !== 0) {
            $reasons[] = ['type' => 'COUNT_DIFF', 'pos' => (int) $pos['trx_count'], 'finance' => (int) $finance['trx_count'], 'diff' => $countDiff];
        }

        $variance = Money::of($pos['cash_variance'] ?? '0');
        if (! Money::isZero($variance)) {
            $reasons[] = ['type' => 'CASH_VARIANCE', 'amount' => $variance];
        }

        if ($reasons === []) {
            return ['status' => 'matched', 'reasons' => []];
        }

        // Hint for the operator: the usual cause of AMOUNT/COUNT differences.
        if (($pos['unsynced_count'] ?? 0) > 0) {
            $reasons[] = [
                'type' => 'UNSYNCED_TRANSACTIONS',
                'count' => (int) $pos['unsynced_count'],
                'message' => 'Ada transaksi POS yang belum tersinkron ke Finance; lakukan resync lalu jalankan ulang.',
            ];
        }

        return ['status' => 'mismatch', 'reasons' => $reasons];
    }
}
