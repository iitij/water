<?php

declare(strict_types=1);

namespace App\Support;

/** Existing storage widths, not permission to widen an endpoint or a source. */
enum DecimalDomain: int
{
    case D10 = 10;
    case D12 = 12;
    case D14 = 14;

    public function maxMinor(): int
    {
        return match ($this) {
            self::D10 => FinancialAmount::METER_BALANCE_MAX,
            self::D12 => ExactMoney::MAX_MINOR,
            self::D14 => CashMoney::MAX_MINOR,
        };
    }
}
