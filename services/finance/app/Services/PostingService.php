<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Posting;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Records POS events as idempotent postings with balanced journal lines. */
class PostingService
{
    public function __construct(private readonly JournalBuilder $journal) {}

    /**
     * @return array{0: Posting, 1: bool} [posting, created]; created=false means duplicate (no new journal)
     */
    public function record(array $event): array
    {
        $expectedKey = "{$event['trx_number']}:{$event['entry_type']}";
        if ($event['idempotency_key'] !== $expectedKey) {
            throw ApiException::unprocessable('INVALID_IDEMPOTENCY_KEY', "idempotency_key harus {$expectedKey}");
        }

        if ($existing = $this->find($event['idempotency_key'])) {
            return [$existing, false];
        }

        if ($event['entry_type'] === Posting::REVERSAL) {
            $event = $this->alignWithSale($event);
        }

        try {
            $lines = $this->journal->lines(
                $event['entry_type'], $event['payment_method'],
                $event['net_sales_amount'], $event['tax_amount'], $event['total_amount'],
            );
        } catch (InvalidArgumentException $e) {
            throw ApiException::unprocessable('INVALID_AMOUNTS', $e->getMessage());
        }

        try {
            $posting = DB::transaction(function () use ($event, $lines) {
                $posting = Posting::create([
                    ...collect($event)->only([
                        'idempotency_key', 'trx_number', 'entry_type', 'outlet_id', 'outlet_code', 'shift_id',
                        'business_date', 'payment_method', 'net_sales_amount', 'tax_amount', 'total_amount',
                    ])->all(),
                    'posted_at' => now(),
                ]);
                $posting->lines()->createMany($lines);

                return $posting;
            });
        } catch (QueryException $e) {
            // Concurrent duplicate: the UNIQUE(idempotency_key) index guarantees a single posting.
            if (($e->errorInfo[1] ?? null) === 1062 && $existing = $this->find($event['idempotency_key'])) {
                return [$existing, false];
            }
            throw $e;
        }

        return [$posting->load('lines'), true];
    }

    /**
     * A reversal must reference an existing sale and mirror it exactly (same method and amounts).
     */
    private function alignWithSale(array $event): array
    {
        $sale = Posting::where('idempotency_key', "{$event['trx_number']}:sale")->first();
        if (! $sale) {
            throw ApiException::unprocessable('SALE_NOT_FOUND', "Reversal ditolak: posting sale untuk {$event['trx_number']} belum ada");
        }

        foreach (['net_sales_amount', 'tax_amount', 'total_amount'] as $field) {
            if (Money::cmp($sale->{$field}, $event[$field]) !== 0) {
                throw ApiException::unprocessable('REVERSAL_AMOUNT_MISMATCH', "Nominal reversal ({$field}) harus sama dengan sale asal", [
                    'field' => $field, 'sale' => $sale->{$field}, 'reversal' => $event[$field],
                ]);
            }
        }

        return [
            ...$event,
            'payment_method' => $sale->payment_method,
            'outlet_id' => $sale->outlet_id,
            'outlet_code' => $sale->outlet_code,
            'shift_id' => $sale->shift_id,
            'business_date' => $sale->business_date->format('Y-m-d'),
        ];
    }

    private function find(string $key): ?Posting
    {
        return Posting::with('lines')->where('idempotency_key', $key)->first();
    }
}
