<?php

namespace App\Services;

use App\Models\Account;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Pure double-entry rules (no I/O).
 *
 *   sale      D 1101/1102/1103 (by method) = total    | K 4101 Penjualan = net sales, K 2101 Utang PPN = tax
 *   reversal  exactly the same lines with debit and credit swapped
 */
final class JournalBuilder
{
    public const METHOD_ACCOUNTS = [
        'cash' => Account::CASH,
        'debit' => Account::BANK_DEBIT,
        'qris' => Account::QRIS_RECEIVABLE,
    ];

    /** @return list<array{account_code: string, debit: string, credit: string}> */
    public function lines(string $entryType, string $method, string $net, string $tax, string $total): array
    {
        if (! isset(self::METHOD_ACCOUNTS[$method])) {
            throw new InvalidArgumentException("Metode bayar tidak dikenal: {$method}");
        }
        if (Money::cmp(Money::add($net, $tax), $total) !== 0) {
            throw new InvalidArgumentException('net_sales_amount + tax_amount harus sama dengan total_amount');
        }

        $lines = [
            ['account_code' => self::METHOD_ACCOUNTS[$method], 'debit' => $total, 'credit' => '0.00'],
            ['account_code' => Account::SALES, 'debit' => '0.00', 'credit' => $net],
        ];
        if (! Money::isZero($tax)) {
            $lines[] = ['account_code' => Account::VAT_PAYABLE, 'debit' => '0.00', 'credit' => $tax];
        }

        if ($entryType === 'reversal') {
            $lines = array_map(fn (array $l) => [...$l, 'debit' => $l['credit'], 'credit' => $l['debit']], $lines);
        }

        return $lines;
    }

    /** @param iterable<array{debit: string, credit: string}|object> $lines */
    public static function isBalanced(iterable $lines): bool
    {
        [$debit, $credit] = self::totals($lines);

        return Money::cmp($debit, $credit) === 0;
    }

    /** @return array{0: string, 1: string} [total debit, total credit] */
    public static function totals(iterable $lines): array
    {
        $debit = $credit = '0.00';
        foreach ($lines as $line) {
            $debit = Money::add($debit, (string) data_get($line, 'debit'));
            $credit = Money::add($credit, (string) data_get($line, 'credit'));
        }

        return [$debit, $credit];
    }
}
