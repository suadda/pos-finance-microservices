<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

/**
 * Pushes sale / reversal events of a transaction to Finance and records the outcome on the
 * transaction (finance_sync_status / finance_synced_at / finance_last_error).
 *
 * Failure never propagates: payment / void already committed, only the sync status says "failed".
 * Every event carries an idempotency_key ({trx_number}:sale / :reversal), so re-sending is always safe:
 * a voided transaction re-sends its sale first (no-op if Finance already has it), then the reversal.
 */
class FinanceSyncService
{
    public function __construct(private readonly FinanceClient $client) {}

    /** @return bool true when Finance acknowledged every event */
    public function sync(Transaction $trx): bool
    {
        return $this->attempt($trx)['ok'];
    }

    /**
     * Retries many transactions once; stops early when Finance is unreachable
     * (avoids N × timeout when the whole service is down).
     *
     * @param  iterable<Transaction>  $transactions
     */
    public function syncMany(iterable $transactions): void
    {
        foreach ($transactions as $trx) {
            if ($this->attempt($trx)['unreachable']) {
                return;
            }
        }
    }

    /** @return array{ok: bool, unreachable: bool} */
    private function attempt(Transaction $trx): array
    {
        $events = match ($trx->status) {
            Transaction::PAID => ['sale'],
            Transaction::VOID => ['sale', 'reversal'],
            default => [],
        };
        if ($events === []) {
            return ['ok' => false, 'unreachable' => false];
        }

        $statusAtStart = $trx->status;
        $this->record($trx, $statusAtStart, ['finance_sync_status' => Transaction::SYNC_PENDING]);

        foreach ($events as $event) {
            $result = $this->client->post($this->payload($trx, $event));
            if (! $result['ok']) {
                Log::warning('finance sync failed', ['trx' => $trx->trx_number, 'event' => $event, 'error' => $result['error']]);
                $this->record($trx, $statusAtStart, [
                    'finance_sync_status' => Transaction::SYNC_FAILED,
                    'finance_last_error' => "[{$event}] ".$result['error'],
                ]);

                return ['ok' => false, 'unreachable' => $result['unreachable']];
            }
            // finance_synced_at = last event acknowledged by Finance (tells "sale delivered" apart for voids).
            $this->record($trx, $statusAtStart, ['finance_synced_at' => now()]);
        }

        $this->record($trx, $statusAtStart, [
            'finance_sync_status' => Transaction::SYNC_SYNCED,
            'finance_last_error' => null,
        ]);

        return ['ok' => true, 'unreachable' => false];
    }

    /**
     * Conditional update: if the transaction changed status meanwhile (e.g. voided while the sale
     * was in flight), this attempt's outcome is stale and the newer attempt owns the sync status.
     */
    private function record(Transaction $trx, string $statusAtStart, array $values): void
    {
        $updated = Transaction::whereKey($trx->id)->where('status', $statusAtStart)->update($values);
        if ($updated) {
            $trx->forceFill($values)->syncOriginal();
        }
    }

    public function payload(Transaction $trx, string $event): array
    {
        return [
            'idempotency_key' => "{$trx->trx_number}:{$event}",
            'trx_number' => $trx->trx_number,
            'entry_type' => $event,
            'outlet_id' => $trx->outlet_id,
            'outlet_code' => $trx->outlet_code,
            'shift_id' => $trx->shift_id,
            'business_date' => $trx->business_date->format('Y-m-d'),
            'payment_method' => $trx->payment_method,
            'net_sales_amount' => $trx->netSales(),
            'tax_amount' => $trx->tax_amount,
            'total_amount' => $trx->grand_total,
            'occurred_at' => ($event === 'sale' ? $trx->paid_at : $trx->voided_at)?->toIso8601String(),
        ];
    }
}
