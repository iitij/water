<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/** Exact treasury document and running-balance domains. */
final class TreasuryMoney
{
    /** Raw document amount in cents, bounded by the source DECIMAL(14,2) contract. */
    public static function document(mixed $raw, bool $positive = false): int
    {
        $minor = MoneyBoundary::readMinor($raw, true, DecimalDomain::D14);
        if ($positive && $minor <= 0) {
            throw new InvalidArgumentException('Positive treasury document amount required.');
        }
        return $minor;
    }

    /** A recorded balance or adjustment can be wider than an individual document. */
    public static function signed(mixed $raw): int
    {
        return SignedMinor::parse($raw);
    }

    public static function variance(mixed $counted, mixed $recorded): int
    {
        return SignedMinor::subtract(self::document($counted), self::signed($recorded));
    }

    /** Gross movement and running balance must each remain in the reversible int64 domain. */
    public static function accumulate(int $balance, int $incoming, int $outgoing, mixed $rawPosting): array
    {
        $amount = self::signed($rawPosting);
        if ($incoming < 0 || $outgoing < 0) {
            throw new InvalidArgumentException('Gross treasury totals cannot be negative.');
        }
        return [
            'balance' => SignedMinor::add($balance, $amount),
            'incoming' => SignedMinor::add($incoming, max(0, $amount)),
            'outgoing' => SignedMinor::add($outgoing, max(0, SignedMinor::negate($amount))),
        ];
    }
}
