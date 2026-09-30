<?php

namespace Tests\Unit;

use App\Services\ReconciliationComparator;
use PHPUnit\Framework\TestCase;

class ReconciliationComparatorTest extends TestCase
{
    private const S1_POS = [
        'totals' => ['cash' => '111000.00', 'debit' => '0.00', 'qris' => '55500.00'],
        'trx_count' => 2,
        'cash_variance' => '0.00',
        'unsynced_count' => 0,
    ];

    public function test_s1_happy_path_is_matched(): void
    {
        $finance = ['totals' => ['cash' => '111000.00', 'debit' => '0.00', 'qris' => '55500.00'], 'trx_count' => 2];

        $result = (new ReconciliationComparator)->compare(self::S1_POS, $finance);

        $this->assertSame('matched', $result['status']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_s2_missing_qris_posting_is_mismatch(): void
    {
        $pos = [...self::S1_POS, 'unsynced_count' => 1];
        $finance = ['totals' => ['cash' => '111000.00', 'debit' => '0.00', 'qris' => '0.00'], 'trx_count' => 1];

        $result = (new ReconciliationComparator)->compare($pos, $finance);

        $this->assertSame('mismatch', $result['status']);
        $this->assertSame(
            ['type' => 'AMOUNT_DIFF', 'method' => 'qris', 'pos' => '55500.00', 'finance' => '0.00', 'diff' => '55500.00'],
            $result['reasons'][0],
        );
        $this->assertSame(['type' => 'COUNT_DIFF', 'pos' => 2, 'finance' => 1, 'diff' => 1], $result['reasons'][1]);
        $this->assertSame('UNSYNCED_TRANSACTIONS', $result['reasons'][2]['type']);
    }

    public function test_s3_cash_variance_alone_causes_mismatch(): void
    {
        $pos = [...self::S1_POS, 'cash_variance' => '-5000.00'];
        $finance = ['totals' => self::S1_POS['totals'], 'trx_count' => 2];

        $result = (new ReconciliationComparator)->compare($pos, $finance);

        $this->assertSame('mismatch', $result['status']);
        $this->assertSame([['type' => 'CASH_VARIANCE', 'amount' => '-5000.00']], $result['reasons']);
    }
}
