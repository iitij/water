<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\HeldCashReleasePolicy;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Internal read-only planning boundary. The caller cannot supply proof,
 * balances or amounts. A preview is not permission to spend or close.
 */
final class HeldCashReleaseReadiness
{
    public function preview(int $accountId, DateTimeImmutable $now): array
    {
        return $this->withinPreview($accountId, $now, static fn (array $preview): array => $preview);
    }

    /** Internal candidate writer callback under the same scope-first transaction. */
    public function withinPreview(int $accountId, DateTimeImmutable $now, callable $reader): mixed
    {
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($accountId, $date, $reader): mixed {
                if (! app(AllocationHistoryProtection::class)->active()
                    || ! app(HeldCashDocumentProtection::class)->active()) {
                    throw new DomainException('held_release_protection_missing');
                }
                $proof = app(HeldCashCreditAllocationEvidence::class)->inspectAccount($accountId);
                $reserve = app(HeldCashReservation::class);
                $outstanding = $reserve->outstandingForAccount($accountId);
                $pending = $reserve->unpostedCashRefundMinor();
                $balance = app(HeldCashRecordedLiquidityEvidence::class)->balance($accountId, $date);
                // Active production policy remains R=0. Do not accept a release
                // amount from a caller or infer that an allocation grants one.
                $credits = array_map(static fn (array $credit): array =>
                    $credit + ['released_minor' => 0], $proof['credits']);
                $result = app(HeldCashReleasePolicy::class)->evaluate($balance, $pending, $outstanding, $credits);
                if ($result['protected_minor'] !== $reserve->protectedForAccount($accountId)
                    || $result['credited_minor'] !== $proof['credited_minor']) {
                    throw new DomainException('held_release_protection_mismatch');
                }
                return $reader($result + [
                    'account_id' => $accountId, 'effective_on' => $date,
                    'period_id' => $context['period_id'], 'credits' => $credits,
                    'source_coverage_verified' => false, 'closure_authorized' => false,
                ]);
            });
    }
}
