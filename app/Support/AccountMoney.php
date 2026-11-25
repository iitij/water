<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/** Raw financial documents and ledger cents, never rounded Eloquent presentation casts. */
final class AccountMoney
{
    public static function stored(Model $model, string $field = 'amount'): int
    {
        return MoneyBoundary::readDecimal($model->getRawOriginal($field), DecimalDomain::D12,
            $model->getConnection()->getDriverName(), signed: true);
    }

    public static function unsigned(mixed $raw): int
    {
        return MoneyBoundary::readMinor($raw, true);
    }

    public static function positiveAllocation(mixed $raw): int
    {
        $minor = MoneyBoundary::readMinor($raw, true, DecimalDomain::D12);
        if ($minor <= 0) {
            throw new \InvalidArgumentException('Positive allocation amount required.');
        }
        return $minor;
    }
}
