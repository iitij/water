<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/** Exact cash custody boundary; physical cash is never inferred from rounded totals. */
final class CashCustodyAmounts
{
    public static function input(mixed $raw, string $field, DecimalDomain $domain = DecimalDomain::D14,
        int $minimum = 0, ?int $maximum = null, bool $digitsOnly = false): int
    {
        try {
            return $digitsOnly
                ? MoneyBoundary::regexInput($raw, $domain, false, $minimum, $maximum)
                : MoneyBoundary::numericInput($raw, $domain, false, $minimum, $maximum);
        } catch (InvalidArgumentException|OverflowException) {
            throw ValidationException::withMessages([$field => 'المبلغ غير صالح أو خارج النطاق أو ليس عدداً صحيحاً من أجزاء المئة.']);
        }
    }

    public static function stored(mixed $raw, string $driver, DecimalDomain $domain = DecimalDomain::D14,
        bool $signed = false, bool $nullable = false): ?int
    {
        return MoneyBoundary::readDecimal($raw, $domain, $driver, $nullable, $signed);
    }

    public static function positiveStored(mixed $raw, string $driver, DecimalDomain $domain): int
    {
        $minor = self::stored($raw, $driver, $domain);
        if ($minor <= 0) {
            throw new InvalidArgumentException('Positive stored cash amount required.');
        }
        return $minor;
    }

    public static function sessionDecimal(int $minor, bool $signed = false): string
    {
        return MoneyBoundary::writeDecimal($minor, DecimalDomain::D14, $signed);
    }

    /** Bound the finalized expected value to its actual storage domain. */
    public static function expected(int $opening, iterable $receipts): int
    {
        self::sessionDecimal($opening);
        $total = $opening;
        foreach ($receipts as $receipt) {
            MoneyBoundary::writeDecimal($receipt, DecimalDomain::D12);
            if ($receipt <= 0) {
                throw new InvalidArgumentException('Positive live cash receipt required.');
            }
            $total = SignedMinor::add($total, $receipt);
            self::sessionDecimal($total);
        }
        return $total;
    }

    public static function variance(int $counted, int $expected): int
    {
        self::sessionDecimal($counted);
        self::sessionDecimal($expected);
        $variance = SignedMinor::subtract($counted, $expected);
        self::sessionDecimal($variance, true);
        return $variance;
    }

    /** Never treat an incomplete or inconsistent snapshot as allocatable cash. */
    public static function consistent(?int $counted, ?int $expected, ?int $variance): bool
    {
        return $counted !== null && $expected !== null && $variance !== null
            && $counted >= 0 && $expected >= 0 && self::variance($counted, $expected) === $variance;
    }
}
