<?php

namespace Tests\Unit;

use App\Services\JournalBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class JournalBuilderTest extends TestCase
{
    public function test_cash_sale_matches_s1_t1(): void
    {
        $lines = (new JournalBuilder)->lines('sale', 'cash', '100000.00', '11000.00', '111000.00');

        $this->assertSame([
            ['account_code' => '1101', 'debit' => '111000.00', 'credit' => '0.00'],
            ['account_code' => '4101', 'debit' => '0.00', 'credit' => '100000.00'],
            ['account_code' => '2101', 'debit' => '0.00', 'credit' => '11000.00'],
        ], $lines);
        $this->assertTrue(JournalBuilder::isBalanced($lines));
    }

    public function test_method_selects_debit_account(): void
    {
        $builder = new JournalBuilder;
        $this->assertSame('1102', $builder->lines('sale', 'debit', '200000.00', '22000.00', '222000.00')[0]['account_code']);
        $this->assertSame('1103', $builder->lines('sale', 'qris', '50000.00', '5500.00', '55500.00')[0]['account_code']);
    }

    public function test_reversal_swaps_sides_with_identical_amounts(): void
    {
        $builder = new JournalBuilder;
        $sale = $builder->lines('sale', 'debit', '200000.00', '22000.00', '222000.00');
        $reversal = $builder->lines('reversal', 'debit', '200000.00', '22000.00', '222000.00');

        foreach ($sale as $i => $line) {
            $this->assertSame($line['account_code'], $reversal[$i]['account_code']);
            $this->assertSame($line['debit'], $reversal[$i]['credit']);
            $this->assertSame($line['credit'], $reversal[$i]['debit']);
        }
        $this->assertTrue(JournalBuilder::isBalanced($reversal));
    }

    public function test_zero_tax_line_is_omitted(): void
    {
        $lines = (new JournalBuilder)->lines('sale', 'cash', '0.00', '0.00', '0.00');
        $this->assertCount(2, $lines);
    }

    public function test_inconsistent_amounts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new JournalBuilder)->lines('sale', 'cash', '100000.00', '11000.00', '110000.00');
    }
}
