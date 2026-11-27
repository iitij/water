<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use LogicException;

/** Fail closed before writing treasury documents or registry effects. */
final class TreasuryFinancialWriteProtection
{
    public function requireActive(): void
    {
        try {
            $active = app(FinancialSourceInsertProtection::class)->active()
                && app(DatedFinancialSourcePeriodProtection::class)->active()
                && app(SessionTransferDestinationProtection::class)->active()
                && app(FinancialPostingRegistryProtection::class)->active()
                && app(RegistrySourceIdentityProtection::class)->active();
        } catch (LogicException $error) {
            throw new DomainException('treasury_financial_write_protection_missing', 0, $error);
        }
        if (! $active) {
            throw new DomainException('treasury_financial_write_protection_missing');
        }
    }
}
