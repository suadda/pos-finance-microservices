<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Support\Money;

/**
 * Pure pricing/payment rules (no I/O) so they are easy to unit test.
 *
 *   subtotal    = Σ quantity × unit_price
 *   0 <= discount_amount <= subtotal
 *   tax_amount  = ROUND((subtotal - discount_amount) × TAX_RATE, 2)
 *   grand_total = subtotal - discount_amount + tax_amount
 */
final class TransactionCalculator
{
    public function __construct(private readonly string $taxRate) {}

    /**
     * @param  list<array{unit_price: string, quantity: int}>  $lines
     * @return array{lines: list<string>, subtotal: string, discount_amount: string, tax_amount: string, grand_total: string}
     */
    public function totals(array $lines, string $discount): array
    {
        $lineTotals = array_map(fn (array $l) => Money::mul($l['unit_price'], $l['quantity']), $lines);
        $subtotal = Money::add(...$lineTotals);
        $discount = Money::of($discount);

        if (Money::cmp($discount, '0') < 0 || Money::cmp($discount, $subtotal) > 0) {
            throw ApiException::unprocessable('INVALID_DISCOUNT', 'discount_amount harus antara 0 dan subtotal', [
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
            ]);
        }

        $net = Money::sub($subtotal, $discount);
        $tax = Money::mul($net, $this->taxRate);

        return [
            'lines' => $lineTotals,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'grand_total' => Money::add($net, $tax),
        ];
    }

    /**
     * Cash: paid >= grand_total, change = paid - grand_total. Debit/QRIS: paid must equal grand_total.
     *
     * @return string change_amount
     */
    public function change(string $grandTotal, string $method, string $paid): string
    {
        $cmp = Money::cmp($paid, $grandTotal);

        if ($method === 'cash') {
            if ($cmp < 0) {
                throw ApiException::unprocessable('INSUFFICIENT_PAYMENT', 'Uang diterima kurang dari total belanja', [
                    'grand_total' => $grandTotal,
                    'paid_amount' => $paid,
                ]);
            }

            return Money::sub($paid, $grandTotal);
        }

        if ($cmp !== 0) {
            throw ApiException::unprocessable('INVALID_PAYMENT_AMOUNT', 'Untuk debit/QRIS, paid_amount harus sama dengan grand_total', [
                'grand_total' => $grandTotal,
                'paid_amount' => $paid,
            ]);
        }

        return '0.00';
    }
}
