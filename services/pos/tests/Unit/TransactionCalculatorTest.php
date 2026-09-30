<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Services\TransactionCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TransactionCalculatorTest extends TestCase
{
    private TransactionCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new TransactionCalculator('0.11');
    }

    /** Scenario S1 figures. */
    public static function s1Cases(): array
    {
        return [
            'T1 cash' => ['100000.00', '111000.00', '11000.00'],
            'T2 qris' => ['50000.00', '55500.00', '5500.00'],
            'T3 debit' => ['200000.00', '222000.00', '22000.00'],
        ];
    }

    #[DataProvider('s1Cases')]
    public function test_s1_totals(string $price, string $grandTotal, string $tax): void
    {
        $totals = $this->calc->totals([['unit_price' => $price, 'quantity' => 1]], '0');

        $this->assertSame($price, $totals['subtotal']);
        $this->assertSame($tax, $totals['tax_amount']);
        $this->assertSame($grandTotal, $totals['grand_total']);
    }

    public function test_multiple_lines_and_discount(): void
    {
        $totals = $this->calc->totals([
            ['unit_price' => '18000.00', 'quantity' => 3],
            ['unit_price' => '8500.00', 'quantity' => 2],
        ], '1000');

        $this->assertSame(['54000.00', '17000.00'], $totals['lines']);
        $this->assertSame('71000.00', $totals['subtotal']);
        $this->assertSame('1000.00', $totals['discount_amount']);
        $this->assertSame('7700.00', $totals['tax_amount']);   // 70000 * 0.11
        $this->assertSame('77700.00', $totals['grand_total']);
    }

    public function test_tax_is_rounded_half_up_to_two_decimals(): void
    {
        // 12345.55 * 0.11 = 1358.0105 -> 1358.01 ; 0.05 * 0.11 = 0.0055 -> 0.01
        $this->assertSame('1358.01', $this->calc->totals([['unit_price' => '12345.55', 'quantity' => 1]], '0')['tax_amount']);
        $this->assertSame('0.01', $this->calc->totals([['unit_price' => '0.05', 'quantity' => 1]], '0')['tax_amount']);
    }

    public function test_discount_cannot_exceed_subtotal(): void
    {
        $this->expectException(ApiException::class);
        $this->calc->totals([['unit_price' => '5000.00', 'quantity' => 1]], '5000.01');
    }

    public function test_full_discount_is_allowed(): void
    {
        $totals = $this->calc->totals([['unit_price' => '5000.00', 'quantity' => 1]], '5000');
        $this->assertSame('0.00', $totals['grand_total']);
    }

    public function test_cash_change(): void
    {
        $this->assertSame('39000.00', $this->calc->change('111000.00', 'cash', '150000.00')); // S1 T1
        $this->assertSame('0.00', $this->calc->change('111000.00', 'cash', '111000.00'));
    }

    public function test_cash_underpayment_is_rejected(): void
    {
        $this->expectException(ApiException::class);
        $this->calc->change('111000.00', 'cash', '110999.99');
    }

    public function test_non_cash_must_be_exact(): void
    {
        $this->assertSame('0.00', $this->calc->change('55500.00', 'qris', '55500.00'));

        $this->expectException(ApiException::class);
        $this->calc->change('55500.00', 'debit', '60000.00');
    }
}
