<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Subscriber;
use App\Support\AccountMoney;
use App\Support\SignedMinor;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Candidate-only automatic re-reservation evidence. Never grants spending
 * authority or changes treasury balances. Uses the existing issuance and
 * subscriber transaction; never acquires the scope or period locks here.
 */
final class HeldCashShadowReinstatement
{
    public function synchronize(Subscriber $subscriber, int $revisionId, bool $createdRevision): void
    {
        $this->need(DB::transactionLevel() > 0, 'shadow_restore_requires_transaction');
        $creditIds = DB::table('treasury_held_cash_credits')->where('subscriber_id', $subscriber->id)->pluck('id');
        if ($creditIds->isEmpty()) return;
        $protection = app(HeldCashReleaseDocumentProtection::class);
        $this->need($protection->journalActive() && $protection->reinstatementActive(), 'held_release_protection_missing');
        $anchors = DB::table('held_cash_release_journal')->whereIn('credit_id', $creditIds)
            ->orderByDesc('id')->lockForUpdate()->get()->unique('credit_id');
        foreach ($anchors as $anchor) {
            $credit = DB::table('treasury_held_cash_credits')->where('id', $anchor->credit_id)->first();
            $before = $this->afterAnchor($anchor, $credit, $revisionId, $createdRevision);
            $revision = $this->revision($revisionId, $credit);
            $applied = $this->applied($revision, $credit);
            $after = min($before, $applied);
            if ($after === $before) continue;
            $this->need(DB::table('held_cash_shadow_reinstatements')->insert([
                'journal_id' => $anchor->id, 'revision_id' => $revisionId,
                'allocation_hash' => $revision->allocation_hash,
                'before_minor' => $before, 'applied_minor' => $applied, 'after_minor' => $after,
            ]), 'shadow_restore_write');
        }
    }

    /** Apply only the reductions following this journal entry, in revision order. */
    public function afterAnchor(object $anchor, object $credit, ?int $throughRevision = null,
        bool $allowPendingLatest = false): int
    {
        $this->need((int) $anchor->credit_id === (int) $credit->id, 'shadow_restore_identity');
        $before = SignedMinor::parse($anchor->after_minor);
        $priorRevision = (int) $anchor->revision_id;
        $next = DB::table('held_cash_release_journal')->where('credit_id', $credit->id)
            ->where('id', '>', $anchor->id)->orderBy('id')->lockForUpdate()->first();
        $limit = $next === null ? $throughRevision : min((int) $next->revision_id, $throughRevision ?? PHP_INT_MAX);
        $this->need($limit === null || $limit >= $priorRevision, 'shadow_restore_order');
        $events = DB::table('held_cash_shadow_reinstatements')->where('journal_id', $anchor->id)
            ->orderBy('revision_id')->lockForUpdate()->get()->keyBy('revision_id');
        $revisions = DB::table('payment_allocation_revisions')->where('subscriber_id', $credit->subscriber_id)
            ->where('id', '>', $priorRevision)
            ->when($limit !== null, fn ($q) => $q->where('id', '<=', $limit))
            ->orderBy('id')->lockForUpdate()->get();
        // Verify every intervening allocation, including a temporary drop that
        // a subsequent increase could otherwise conceal.
        foreach ($revisions as $revision) {
            $id = (int) $revision->id;
            $event = $events->get($id);
            if ($before === 0) {
                $this->need($event === null, 'shadow_restore_evidence');
                continue;
            }
            $revision = $this->revision($id, $credit);
            $applied = $this->applied($revision, $credit);
            $after = min($before, $applied);
            if ($after === $before) {
                $this->need($event === null, 'shadow_restore_evidence');
                continue;
            }
            if ($event === null) {
                // Only the revision being written in this very transaction may
                // be missing its event. Never repair an older missing event.
                $this->need($allowPendingLatest && $next === null && $id === $throughRevision,
                    'shadow_restore_missing_event');
                continue;
            }
            $this->need(hash_equals($revision->allocation_hash, $event->allocation_hash)
                && SignedMinor::parse($event->before_minor) === $before
                && SignedMinor::parse($event->applied_minor) === $applied
                && SignedMinor::parse($event->after_minor) === $after, 'shadow_restore_evidence');
            $events->forget($id);
            $before = $after;
        }
        $this->need($events->isEmpty(), 'shadow_restore_order');
        return $before;
    }

    private function revision(int $id, object $credit): object
    {
        $revision = DB::table('payment_allocation_revisions')->where('id', $id)->lockForUpdate()->first();
        $this->need($revision !== null && (int) $revision->subscriber_id === (int) $credit->subscriber_id
            && app(AllocationSnapshotIntegrity::class)->valid($revision), 'shadow_restore_revision');
        return $revision;
    }

    private function applied(object $revision, object $credit): int
    {
        $amount = \App\Support\MoneyBoundary::readDecimal($credit->amount, \App\Support\DecimalDomain::D12,
            DB::getDriverName(), signed: true);
        $total = $applied = 0;
        foreach (DB::table('payment_allocations')->where('revision_id', $revision->id)
            ->where('held_cash_credit_id', $credit->id)->orderBy('position')->lockForUpdate()->get() as $row) {
            $minor = AccountMoney::positiveAllocation($row->amount_minor);
            $total = SignedMinor::add($total, $minor);
            if ($row->invoice_id !== null) {
                $invoice = DB::table('invoices')->where('id', $row->invoice_id)->first();
                $this->need($invoice !== null && (int) $invoice->subscriber_id === (int) $credit->subscriber_id,
                    'shadow_restore_identity');
                $applied = SignedMinor::add($applied, $minor);
            }
        }
        $this->need($amount > 0 && $total === $amount, 'shadow_restore_evidence');
        return $applied;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
