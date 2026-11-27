<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use LogicException;

/** Fail closed on missing or mismatched live source guards, not a certificate
 * of exclusive writer coverage or permission to close a financial period. */
final class SubscriberFinancialWriteProtection
{
    public function requireActive(): void
    {
        try {
            $active = app(FinancialSourceInsertProtection::class)->active()
                && app(LedgerSourceInsertProtection::class)->active()
                && app(FinancialPostingRegistryProtection::class)->active()
                && app(RegistrySourceIdentityProtection::class)->active()
                && app(RegisteredDocumentProtection::class)->active()
                && app(InvoiceProjectionProtection::class)->active()
                && app(AllocationHistoryProtection::class)->active();
        } catch (LogicException $error) {
            throw new DomainException('subscriber_financial_write_protection_missing', 0, $error);
        }
        if (! $active) {
            throw new DomainException('subscriber_financial_write_protection_missing');
        }
    }
}
