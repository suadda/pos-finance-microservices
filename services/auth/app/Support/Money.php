<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Fixed-point money arithmetic on decimal strings (bcmath) — never floats.
 * All results are normalised to 2 decimal places, e.g. "111000.00".
 */
final class Money
{
    private const SCALE = 2;

    public static function of(string|int|null $value): string
    {
        $value = trim((string) ($value ?? '0'));
        if ($value === '') {
            $value = '0';
        }
        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Nilai uang tidak valid: {$value}");
        }

        return self::round($value);
    }

    public static function add(string ...$values): string
    {
        return array_reduce($values, fn (string $sum, string $v) => bcadd($sum, $v, self::SCALE), '0.00');
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    /** Multiply then round to 2 decimals (used for qty x price and tax). */
    public static function mul(string $a, string|int $b): string
    {
        return self::round(bcmul($a, (string) $b, 8));
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function isZero(string $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    /** Round half away from zero to 2 decimals (same semantics as MySQL ROUND(x, 2)). */
    public static function round(string $value): string
    {
        $offset = str_starts_with($value, '-') ? '-0.005' : '0.005';

        // bcadd with scale 2 truncates, so adding the half-cent offset first yields rounding.
        return bcadd(bcadd($value, $offset, 8), '0', self::SCALE);
    }
}
