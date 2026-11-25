<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

/** Portable symmetric signed int64 minor units; never coerce raw values. */
final class SignedMinor
{
    private const MAX_DIGITS = '9223372036854775807';

    public static function requireInt(mixed $value): int
    {
        if (PHP_INT_SIZE !== 8) {
            throw new OverflowException('64-bit PHP required.');
        }
        if (! is_int($value)) {
            throw new InvalidArgumentException('Native integer minor units required.');
        }
        if ($value === PHP_INT_MIN) {
            throw new OverflowException('Minor units outside reversible signed range.');
        }

        return $value;
    }

    /** Raw DB integer: native int or canonical ASCII integer string. */
    public static function parse(mixed $value): int
    {
        if (is_int($value)) {
            return self::requireInt($value);
        }
        if (! is_string($value) || preg_match('/\A(?:0|-?[1-9][0-9]*)\z/', $value) !== 1) {
            throw new InvalidArgumentException('Canonical integer minor units required.');
        }
        $negative = $value[0] === '-';
        $digits = $negative ? substr($value, 1) : $value;
        if (PHP_INT_SIZE !== 8 || strlen($digits) > 19
            || (strlen($digits) === 19 && strcmp($digits, self::MAX_DIGITS) > 0)) {
            throw new OverflowException('Minor units outside reversible signed range.');
        }

        return $negative ? -(int) $digits : (int) $digits;
    }

    /** Unsigned SQL storage does not enlarge the portable native range. */
    public static function parseUnsigned(mixed $value): int
    {
        $minor = self::parse($value);
        if ($minor < 0) {
            throw new InvalidArgumentException('Unsigned minor units must be nonnegative.');
        }

        return $minor;
    }

    public static function add(mixed $left, mixed $right): int
    {
        // Reuse the already accepted checked addition, with strict type guards
        // even when called from a non-strict PHP file.
        return FinancialAmount::add(self::requireInt($left), self::requireInt($right));
    }

    public static function negate(mixed $value): int
    {
        return -self::requireInt($value);
    }

    public static function subtract(mixed $left, mixed $right): int
    {
        return self::add($left, self::negate($right));
    }

    /** Ordered sum; reject an intermediate overflow even if later terms offset it. */
    public static function sum(iterable $values): int
    {
        $total = 0;
        foreach ($values as $value) {
            $total = self::add($total, $value);
        }

        return $total;
    }

    /** Exact presentation of native minor units; no floating division. */
    public static function decimal(mixed $value): string
    {
        $minor = self::requireInt($value);
        $digits = str_pad((string) abs($minor), 3, '0', STR_PAD_LEFT);

        return ($minor < 0 ? '-' : '').substr($digits, 0, -2).'.'.substr($digits, -2);
    }
}
