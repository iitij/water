<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Subscriber;
use App\Support\SignedMinor;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Explicit prospective activation of a new independent release, never adoption
 * of an existing shadow decision. First slice: one release anchor per credit.
 */
final class ActiveHeldCashRelease
{
    public function hasAccount(int $accountId): bool
    {
        return DB::table('held_cash_active_releases')->where('treasury_account_id', $accountId)->exists();
    }

    public function amount(int $accountId): int
    {
        if (! $this->hasAccount($accountId)) return 0;
        $proof = app(HeldCashReleaseReadiness::class)->lockedPreview($accountId);
        $state = app(HeldCashReleaseProposalService::class)->verifiedActiveState($proof);
        return SignedMinor::sum($state);
    }

    public function afterAllocation(Subscriber $subscriber, int $revisionId, bool $created): void
    {
        if (! $created) return;
        $markers = DB::table('held_cash_active_releases as a')
            ->join('treasury_held_cash_credits as c', 'c.id', '=', 'a.credit_id')
            ->where('c.subscriber_id', $subscriber->id)->orderBy('a.treasury_account_id')
            ->select('a.*')->lockForUpdate()->get();
        if ($markers->isEmpty()) return;
        // Never acquire scope locks after issuance. Ungated allocation-changing
        // paths cannot touch actively released credit.
        FinancialWriteGate::currentContext();
        foreach ($markers->pluck('treasury_account_id')->unique() as $accountId) {
            $proof = app(HeldCashReleaseReadiness::class)->lockedPreview((int) $accountId);
            $active = app(HeldCashReleaseProposalService::class)->verifiedActiveState($proof);
            $restored = DB::table('held_cash_shadow_reinstatements as s')
                ->join('held_cash_active_releases as a', 'a.journal_id', '=', 's.journal_id')
                ->where('a.treasury_account_id', $accountId)->where('s.revision_id', $revisionId)->exists();
            if ($restored && SignedMinor::add($proof['margin_minor'], SignedMinor::sum($active)) < 0) {
                // Candidate optional mutation must roll back in its entirety.
                // Externally completed events need a separate deficit workflow.
                throw new DomainException('active_held_release_reinstatement_liquidity');
            }
        }
    }

    public function verifyMarker(object $marker, object $journal): void
    {
        $audits = AuditEvent::query()->where('event', 'treasury.held_release_activated')
            ->where('entity_type', 'held_cash_active_release')->where('entity_id', $journal->operation_uuid)
            ->lockForUpdate()->get();
        if ($audits->count() !== 1 || (int) $audits->first()->actor_user_id !== (int) $journal->actor_id
            || (int) $marker->credit_id !== (int) $journal->credit_id
            || (int) $marker->treasury_account_id !== (int) $journal->treasury_account_id
            || $journal->kind !== 'release') throw new DomainException('active_held_release_evidence');
        $expected = ['journal_id' => (int) $journal->id, 'credit_id' => (int) $journal->credit_id,
            'treasury_account_id' => (int) $journal->treasury_account_id];
        if ($audits->first()->after !== $expected) throw new DomainException('active_held_release_evidence');
    }
}
