<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates TRX/{OUTLET}/{YYYYMMDD}/{NNNN}, safe under concurrent requests.
 *
 * A single atomic upsert increments the per-(outlet, business_date) counter; the row stays
 * locked until the surrounding DB transaction commits, so two simultaneous requests are
 * serialised by InnoDB and can never obtain the same number. LAST_INSERT_ID(expr) returns
 * the incremented value to this connection only. trx_number is also UNIQUE as a last line of defence.
 * Must be called inside DB::transaction().
 */
class TrxNumberGenerator
{
    public function next(int $outletId, string $outletCode, Carbon $businessDate): string
    {
        $date = $businessDate->format('Y-m-d');

        DB::statement(
            'INSERT INTO trx_sequences (outlet_id, business_date, last_number) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            [$outletId, $date],
        );
        $number = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;

        return sprintf('TRX/%s/%s/%04d', $outletCode, $businessDate->format('Ymd'), $number);
    }
}
