<?php

declare(strict_types=1);

namespace App\Support;

/** Original receipt/posting identity. Caller selects the live original under lock. */
final class ReceiptPostingIdentity
{
    public static function matches(object $receipt, object $posting): bool
    {
        if (! isset($receipt->subscriber_id, $posting->subscriber_id)
            || (string) $receipt->subscriber_id !== (string) $posting->subscriber_id
            || (string) ($receipt->meter_id ?? '') !== (string) ($posting->meter_id ?? '')
            || (string) ($receipt->retired_meter_id ?? '') !== (string) ($posting->retired_meter_id ?? '')) {
            return false;
        }
        try {
            $amount = FinancialAmount::parse($receipt->amount ?? null)->minor;

            return $amount > 0 && $amount === FinancialAmount::parse($posting->amount ?? null)->minor;
        } catch (\InvalidArgumentException|\OverflowException) {
            return false;
        }
    }
}
