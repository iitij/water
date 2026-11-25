<?php

declare(strict_types=1);

namespace App\Support;

/** Internal amounts only; intentionally independent of Invoice status/date policy (ACC11). */
final class InvoiceAmounts
{
    public static function remainingMinor(mixed $total, mixed $paid): int
    {
        $billed = FinancialAmount::parse($total)->minor;
        $allocated = FinancialAmount::parse($paid)->minor;
        if ($billed < 0 || $allocated < 0) {
            throw new \LogicException('Negative billed or allocated amount.');
        }

        return max(0, $billed - $allocated);
    }

    /** Caller supplies only eligible, non-deleted invoices under the account lock. */
    public static function outstandingMinor(iterable $invoices): int
    {
        $total = 0;
        foreach ($invoices as $invoice) {
            $total = FinancialAmount::add($total, self::remainingMinor($invoice->total_amount, $invoice->amount_paid));
        }

        return $total;
    }
}
