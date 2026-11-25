<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Non-operative release proposals. A withdrawal invalidates the proposal;
 * neither document changes cash, creates ledger legs or permits spending.
 * No API, UI or production caller is enabled.
 */
final class HeldCashReleaseProposalService
{
    public function propose(User $actor, int $accountId, int $creditId, int $minor,
        string $uuid, string $reference, string $evidence, DateTimeImmutable $now): array
    {
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        $this->need($minor > 0 && $accountId > 0 && $creditId > 0
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1
            && mb_strlen($reference) >= 3 && mb_strlen($reference) <= 150
            && mb_strlen($evidence) >= 20 && mb_strlen($evidence) <= 4000, 'held_release_proposal_input');
        return app(HeldCashReleaseReadiness::class)->withinPreview($accountId, $now,
            function (array $proof) use ($actor, $accountId, $creditId, $minor, $uuid, $reference, $evidence): array {
                $this->authorize($actor);
                $this->verifyLocked($proof);
                $credit = $this->credit($proof, $creditId);
                $document = DB::table('treasury_held_cash_credits')->where('id', $creditId)->lockForUpdate()->first();
                $this->need($document !== null && (int) $document->approved_by !== (int) $actor->id,
                    'held_release_proposal_independence');
                $old = DB::table('held_cash_release_proposals')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $row = [
                    'operation_uuid' => $uuid, 'credit_id' => $creditId, 'treasury_account_id' => $accountId,
                    'subscriber_id' => $credit['subscriber_id'], 'revision_id' => $credit['revision_id'],
                    'period_id' => $proof['period_id'], 'proposed_by' => (int) $actor->id,
                    'allocation_hash' => $credit['allocation_hash'], 'amount_minor' => $minor,
                    'applied_minor' => $credit['applied_minor'], 'recorded_balance_minor' => $proof['recorded_balance_minor'],
                    'pending_refunds_minor' => $proof['pending_refunds_minor'], 'protected_minor' => $proof['protected_minor'],
                    'effective_on' => $proof['effective_on'], 'reference' => $reference, 'evidence' => $evidence,
                ];
                if ($old !== null) {
                    foreach ($row as $key => $value) {
                        $this->need((string) $old->$key === (string) $value, 'held_release_proposal_conflict');
                    }
                    $this->verifyAudit('treasury.held_release_proposed', $uuid, (array) $old, 'proposed_by');
                    $this->need(! DB::table('held_cash_release_withdrawals')->where('proposal_uuid', $uuid)->exists(),
                        'held_release_proposal_withdrawn');
                    return $this->result($row, false);
                }
                $this->need($minor <= $credit['applied_minor'] && $minor <= $proof['candidate_additional_release_minor'],
                    'held_release_proposal_capacity');
                // Proposals have their own identity namespace and never become
                // a financial posting operation or a receipt sync identifier.
                $this->need(! DB::table('held_cash_release_proposals')->where('reference', $reference)->exists(),
                    'held_release_proposal_conflict');
                $this->need(DB::table('held_cash_release_proposals')->insert($row), 'held_release_proposal_write');
                $this->audit('treasury.held_release_proposed', $uuid, $row, 'proposed_by');
                return $this->result($row, true);
            });
    }

    public function withdraw(User $actor, int $accountId, string $uuid, string $evidence, DateTimeImmutable $now): array
    {
        $evidence = trim($evidence);
        $this->need(mb_strlen($evidence) >= 20 && mb_strlen($evidence) <= 4000, 'held_release_proposal_input');
        return app(HeldCashReleaseReadiness::class)->withinPreview($accountId, $now,
            function (array $proof) use ($actor, $accountId, $uuid, $evidence): array {
                $this->authorize($actor);
                $this->verifyLocked($proof);
                $proposal = DB::table('held_cash_release_proposals')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $this->need($proposal !== null && (int) $proposal->treasury_account_id === $accountId
                    && (int) $proposal->proposed_by !== (int) $actor->id, 'held_release_withdrawal_identity');
                $this->verifyAudit('treasury.held_release_proposed', $uuid, (array) $proposal, 'proposed_by');
                $credit = $this->credit($proof, (int) $proposal->credit_id);
                $this->need($credit['subscriber_id'] === (int) $proposal->subscriber_id
                    && $proof['effective_on'] >= $proposal->effective_on, 'held_release_withdrawal_identity');
                $row = ['proposal_uuid' => $uuid, 'withdrawn_by' => (int) $actor->id,
                    'revision_id' => $credit['revision_id'], 'period_id' => $proof['period_id'],
                    'allocation_hash' => $credit['allocation_hash'], 'applied_minor' => $credit['applied_minor'],
                    'effective_on' => $proof['effective_on'], 'evidence' => $evidence];
                $old = DB::table('held_cash_release_withdrawals')->where('proposal_uuid', $uuid)->lockForUpdate()->first();
                if ($old !== null) {
                    foreach ($row as $key => $value) {
                        $this->need((string) $old->$key === (string) $value, 'held_release_withdrawal_conflict');
                    }
                    $this->verifyAudit('treasury.held_release_withdrawn', $uuid, (array) $old, 'withdrawn_by');
                    return $this->result($row, false);
                }
                $this->need(DB::table('held_cash_release_withdrawals')->insert($row), 'held_release_proposal_write');
                $this->audit('treasury.held_release_withdrawn', $uuid, $row, 'withdrawn_by');
                return $this->result($row, true);
            });
    }

    /** Read-only bidirectional coverage, under the same scope/issuance fence. */
    public function inspect(int $accountId, DateTimeImmutable $now): array
    {
        return app(HeldCashReleaseReadiness::class)->withinPreview($accountId, $now,
            fn (array $proof): array => $this->verifyLocked($proof));
    }

    private function verifyLocked(array $proof): array
    {
        $this->need(app(HeldCashReleaseDocumentProtection::class)->active(), 'held_release_protection_missing');
        $proposals = DB::table('held_cash_release_proposals')->orderBy('operation_uuid')->lockForUpdate()->get()->keyBy('operation_uuid');
        $withdrawals = DB::table('held_cash_release_withdrawals')->orderBy('proposal_uuid')->lockForUpdate()->get()->keyBy('proposal_uuid');
        $audits = AuditEvent::query()->whereIn('event', ['treasury.held_release_proposed', 'treasury.held_release_withdrawn'])
            ->orderBy('id')->lockForUpdate()->get();
        $this->need($audits->count() === $proposals->count() + $withdrawals->count(), 'held_release_coverage');
        foreach ($audits as $audit) {
            $documents = $audit->event === 'treasury.held_release_proposed' ? $proposals : $withdrawals;
            $this->need($audit->entity_type === 'held_cash_release_proposal'
                && $documents->has($audit->entity_id), 'held_release_coverage');
        }
        $current = collect($proof['credits'])->keyBy('credit_id');
        $counts = ['proposals' => 0, 'withdrawn' => 0, 'stale' => 0, 'current' => 0];
        foreach ($proposals as $uuid => $proposal) {
            $this->verifyAudit('treasury.held_release_proposed', $uuid, (array) $proposal, 'proposed_by');
            $credit = DB::table('treasury_held_cash_credits as c')
                ->join('treasury_held_cash_assignments as a', 'a.operation_uuid', '=', 'c.assignment_uuid')
                ->join('treasury_held_cash_liabilities as l', 'l.id', '=', 'a.liability_id')
                ->join('treasury_cash_adjustments as d', 'd.id', '=', 'l.treasury_cash_adjustment_id')
                ->where('c.id', $proposal->credit_id)->select('c.*', 'd.treasury_account_id')->lockForUpdate()->first();
            $this->need($credit !== null && (int) $credit->subscriber_id === (int) $proposal->subscriber_id
                && (int) $credit->treasury_account_id === (int) $proposal->treasury_account_id
                && (int) $credit->approved_by !== (int) $proposal->proposed_by
                && $proposal->effective_on >= $credit->effective_on, 'held_release_coverage_source');
            $amount = \App\Support\MoneyBoundary::readDecimal($credit->amount, \App\Support\DecimalDomain::D12,
                DB::getDriverName(), signed: true);
            $applied = $this->historicalApplied($proposal, (int) $credit->id, (int) $credit->subscriber_id, $amount);
            $minor = SignedMinor::parse($proposal->amount_minor);
            $pending = SignedMinor::parse($proposal->pending_refunds_minor);
            $protected = SignedMinor::parse($proposal->protected_minor);
            $this->need($minor > 0 && $minor <= $applied && $pending >= 0 && $protected >= $amount
                && SignedMinor::subtract(SignedMinor::subtract(SignedMinor::parse($proposal->recorded_balance_minor), $pending), $protected) >= 0,
                'held_release_coverage_amount');
            $withdrawal = $withdrawals->get($uuid);
            if ($withdrawal !== null) {
                $this->verifyAudit('treasury.held_release_withdrawn', $uuid, (array) $withdrawal, 'withdrawn_by');
                $this->need((int) $withdrawal->withdrawn_by !== (int) $proposal->proposed_by
                    && $withdrawal->effective_on >= $proposal->effective_on
                    && (int) $withdrawal->revision_id >= (int) $proposal->revision_id
                    && $audits->first(fn ($a) => $a->entity_id === $uuid && $a->event === 'treasury.held_release_proposed')->id
                        < $audits->first(fn ($a) => $a->entity_id === $uuid && $a->event === 'treasury.held_release_withdrawn')->id,
                    'held_release_coverage_withdrawal');
                $this->historicalApplied($withdrawal, (int) $credit->id, (int) $credit->subscriber_id, $amount);
            }
            if ((int) $proposal->treasury_account_id !== $proof['account_id']) continue;
            $counts['proposals']++;
            $latest = $current->get((int) $credit->id);
            $this->need($latest !== null, 'held_release_coverage_source');
            if ($withdrawal !== null) {
                $counts['withdrawn']++;
            } elseif ($latest['revision_id'] !== (int) $proposal->revision_id
                || ! hash_equals($latest['allocation_hash'], $proposal->allocation_hash)
                || $proof['recorded_balance_minor'] !== SignedMinor::parse($proposal->recorded_balance_minor)
                || $proof['pending_refunds_minor'] !== $pending || $proof['protected_minor'] !== $protected
                || $proof['effective_on'] !== $proposal->effective_on) {
                $counts['stale']++;
            } else {
                $counts['current']++;
            }
        }
        return $counts + ['document_coverage_verified' => true,
            'spending_release_authorized' => false, 'closure_authorized' => false];
    }

    private function historicalApplied(object $document, int $creditId, int $subscriberId, int $amount): int
    {
        $revision = DB::table('payment_allocation_revisions')->where('id', $document->revision_id)->lockForUpdate()->first();
        $period = DB::table('financial_periods')->where('id', $document->period_id)->lockForUpdate()->first();
        $this->need($revision !== null && (int) $revision->subscriber_id === $subscriberId
            && hash_equals($revision->allocation_hash, $document->allocation_hash)
            && app(AllocationSnapshotIntegrity::class)->valid($revision)
            && $period !== null && $period->scope_key === 'water'
            && $document->effective_on >= $period->start_on && $document->effective_on < $period->next_on,
            'held_release_coverage_revision');
        $total = $applied = 0;
        foreach (DB::table('payment_allocations')->where('revision_id', $revision->id)
            ->where('held_cash_credit_id', $creditId)->orderBy('position')->lockForUpdate()->get() as $row) {
            $minor = \App\Support\AccountMoney::positiveAllocation($row->amount_minor);
            $total = SignedMinor::add($total, $minor);
            if ($row->invoice_id !== null) {
                $invoice = DB::table('invoices')->where('id', $row->invoice_id)->lockForUpdate()->first();
                $this->need($invoice !== null && (int) $invoice->subscriber_id === $subscriberId, 'held_release_coverage_revision');
                $applied = SignedMinor::add($applied, $minor);
            }
        }
        $this->need($amount > 0 && $total === $amount && $applied === SignedMinor::parse($document->applied_minor),
            'held_release_coverage_revision');
        return $applied;
    }

    private function credit(array $proof, int $id): array
    {
        foreach ($proof['credits'] as $credit) {
            if ($credit['credit_id'] === $id) return $credit;
        }
        throw new DomainException('held_release_proposal_credit');
    }

    private function authorize(User $actor): void
    {
        $this->need(app(HeldCashReleaseDocumentProtection::class)->active(), 'held_release_protection_missing');
        app(TreasuryRegisterService::class)->authorize(User::query()->findOrFail($actor->id));
    }

    private function audit(string $event, string $id, array $row, string $actor): void
    {
        app(AuditLogger::class)->recordRequired($event, ['entity_type' => 'held_cash_release_proposal',
            'entity_id' => $id, 'actor_user_id' => $row[$actor], 'after' => $row, 'source' => 'internal']);
        $this->verifyAudit($event, $id, $row, $actor);
    }

    private function verifyAudit(string $event, string $id, array $row, string $actor): void
    {
        $audits = AuditEvent::query()->where('event', $event)->where('entity_type', 'held_cash_release_proposal')
            ->where('entity_id', $id)->lockForUpdate()->get();
        $this->need($audits->count() === 1 && (int) $audits->first()->actor_user_id === (int) $row[$actor],
            'held_release_proposal_audit');
        $after = $audits->first()->after ?? [];
        foreach ($row as $key => $value) {
            $this->need(array_key_exists($key, $after) && (string) $after[$key] === (string) $value,
                'held_release_proposal_audit');
        }
    }

    private function result(array $row, bool $created): array
    {
        return $row + ['created' => $created, 'spending_release_authorized' => false,
            'cash_movement_created' => false, 'closure_authorized' => false];
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
