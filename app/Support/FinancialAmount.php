<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * Compatibility boundary for legacy numeric API clients and DECIMAL database
 * values. No arithmetic uses floating point after conversion. The strict
 * ExactMoney contract remains unchanged; legacy numeric syntax is normalized
 * only when it represents an exact number of cents.
 */
final class FinancialAmount
{
    public const METER_BALANCE_MAX = 9999999999;

    public static function parse(mixed $value): ExactMoney
    {
        if (is_float($value)) {
            // A JSON numeric client may already have lost source precision.
            // Accept only the round-trip decimal representation, never round it.
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Finite amount required.');
            }
            $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            if ((float) $encoded !== $value) {
                throw new InvalidArgumentException('Numeric amount cannot be represented losslessly.');
            }
            $value = $encoded;
        } elseif (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || strlen($value) > 256
            || ! preg_match('/\A([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?\z/', trim($value, " \t\n\r\v\f"), $m, PREG_UNMATCHED_AS_NULL)) {
            throw new InvalidArgumentException('Decimal amount required.');
        }
        $fraction = $m[4] ?? $m[3] ?? '';
        $digits = ltrim(($m[2] ?? '').$fraction, '0');
        if ($digits === '') {
            return ExactMoney::fromMinor(0);
        }
        $exponent = $m[5] ?? '0';
        $expDigits = ltrim(ltrim($exponent, '+-'), '0');
        if (strlen($expDigits) > 3 || abs((int) $exponent) > 256) {
            throw new OverflowException('Decimal exponent outside supported range.');
        }
        $shift = (int) $exponent - strlen($fraction) + 2;
        if ($shift < 0) {
            $remove = -$shift;
            if ($remove >= strlen($digits) || substr($digits, -$remove) !== str_repeat('0', $remove)) {
                throw new InvalidArgumentException('Amount is not an exact number of cents.');
            }
            $digits = substr($digits, 0, -$remove);
        } elseif ($shift > 0) {
            if (strlen($digits) + $shift > 12) {
                throw new OverflowException('Amount outside DECIMAL(12,2).');
            }
            $digits .= str_repeat('0', $shift);
        }
        if (strlen($digits) > 12) {
            throw new OverflowException('Amount outside DECIMAL(12,2).');
        }

        return ExactMoney::fromMinor(($m[1] === '-' ? -1 : 1) * (int) $digits);
    }

    public static function validated(mixed $value, string $field = 'amount'): ExactMoney
    {
        try {
            return self::parse($value);
        } catch (InvalidArgumentException|OverflowException $e) {
            throw ValidationException::withMessages([$field => 'القيمة المالية خارج النطاق أو ليست عدداً صحيحاً من أجزاء المئة.']);
        }
    }

    /** Checked signed aggregate; symmetric int64 range allows exact reversal. */
    public static function add(int $left, int $right): int
    {
        if (PHP_INT_SIZE < 8 || $left === PHP_INT_MIN || $right === PHP_INT_MIN
            || ($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < -PHP_INT_MAX - $right)) {
            throw new OverflowException('Financial aggregate exceeds signed integer range.');
        }

        return $left + $right;
    }

    public static function storageDecimal(int $minor, string $field, int $max = ExactMoney::MAX_MINOR): string
    {
        if ($max < 0 || $max > ExactMoney::MAX_MINOR || $minor < -$max || $minor > $max) {
            throw ValidationException::withMessages([$field => 'المجموع المالي يتجاوز سعة حقل التخزين؛ لم تُحفظ العملية.']);
        }

        return ExactMoney::fromMinor($minor)->decimal();
    }
}
