<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashSession;
use App\Models\User;
use App\Support\CustodyShortageSettlementPolicy;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Candidate full recovery only. One physical inflow and its immutable source
 * must commit together with the registry. No subscriber credit or debt. */
final class CustodyShortageRecoveryWriter
{
    public function approve(User $actor, int $sessionId, DateTimeImmutable $now): array
    {
        CompletedCashRefundRequest::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $local = $now->setTimezone(new DateTimeZone('Asia/Aden'));
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $local->format('Y-m-d'), $now,
            function (array $context) use ($actor, $sessionId, $now, $local): array {
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null
                    && app(CustodyShortageRecoveryProposalProtection::class)->active(), 'shortage_recovery_protection_required');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                CompletedCashRefundRequest::authenticated($actor);
                $actor->setRelation('roles', $actor->roles()->lockForUpdate()->get());
                app(TreasuryRegisterService::class)->authorize($actor);
                $session = CashSession::query()->lockForUpdate()->findOrFail($sessionId);
                $proposal = AuditEvent::query()->where('event', CustodyShortageRecoveryProposal::EVENT)
                    ->where('entity_type', 'custody_shortage_recovery')->where('entity_id', (string) $sessionId)
                    ->lockForUpdate()->first();
                $this->need($proposal !== null && $proposal->source === 'internal' && is_array($proposal->after),
                    'shortage_recovery_proposal_missing');
                $p = $proposal->after;
                $source = app(FinancialOutstandingPeriodEvidence::class)
                    ->verifiedShortageSource($session, $local->modify('+1 day')->format('Y-m-d'));
                foreach ($source as $key => $value) {
                    $this->need(($p[$key] ?? null) === $value, 'shortage_recovery_source_changed');
                }
                $this->need(($p['proposed_by'] ?? null) === (int) $proposal->actor_user_id
                    && $p['proposed_by'] > 0 && $p['proposed_by'] !== $source['collector_id']
                    && $p['proposed_by'] !== (int) $actor->id && $source['collector_id'] !== (int) $actor->id,
                    'shortage_recovery_independence');
                $proposalDate = (new DateTimeImmutable($proposal->getRawOriginal('occurred_at'), new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
                $this->need(($p['proposed_on'] ?? null) === $proposalDate && $proposalDate <= $context['effective_on']
                    && is_int($p['account_id'] ?? null) && $p['account_id'] > 0
                    && is_string($p['reference'] ?? null) && mb_strlen($p['reference']) >= 3 && mb_strlen($p['reference']) <= 150
                    && is_string($p['evidence'] ?? null) && mb_strlen($p['evidence']) >= 10 && mb_strlen($p['evidence']) <= 2000
                    && is_string($p['operation_uuid'] ?? null)
                    && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $p['operation_uuid']),
                    'shortage_recovery_proposal_conflict');
                $this->need(! AuditEvent::query()->where('entity_type', 'custody_shortage_loss')
                    ->where('entity_id', (string) $sessionId)->lockForUpdate()->first(), 'shortage_recovery_loss_conflict');
                $account = DB::table('treasury_accounts')->where('id', $p['account_id'])->lockForUpdate()->first();
                $this->need($account && $account->channel === 'cash' && $account->opening_on <= $context['effective_on'],
                    'shortage_recovery_account_invalid');
                $existing = DB::table('custody_shortage_recoveries')->where('cash_session_id', $sessionId)->lockForUpdate()->first();
                if ($existing) {
                    $this->need((int) $existing->approved_by === (int) $actor->id
                        && $existing->effective_on === $context['effective_on'], 'shortage_recovery_replay_conflict');
                    $id = (int) $existing->id;
                    $postingId = (int) $existing->treasury_posting_id;
                } else {
                    $this->need(! DB::table('treasury_accounts')->where('opening_reference', $p['reference'])->exists()
                        && ! DB::table('treasury_postings')->where('evidence_reference', $p['reference'])->exists()
                        && ! DB::table('financial_posting_operations')->where('operation_uuid', $p['operation_uuid'])->exists(),
                        'shortage_recovery_proposal_conflict');
                    $postingId = DB::table('treasury_postings')->insertGetId([
                        'treasury_account_id' => $p['account_id'], 'kind' => 'custody_shortage_recovery',
                        'amount_minor' => $source['shortage_minor'], 'effective_on' => $context['effective_on'],
                        'evidence_reference' => $p['reference'], 'posted_by' => $actor->id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $id = DB::table('custody_shortage_recoveries')->insertGetId([
                        'cash_session_id' => $sessionId, 'treasury_account_id' => $p['account_id'],
                        'treasury_posting_id' => $postingId, 'count_approval_id' => $source['count_approval_id'],
                        'proposal_id' => $proposal->id, 'proposed_by' => $p['proposed_by'], 'approved_by' => $actor->id,
                        'amount_minor' => $source['shortage_minor'], 'effective_on' => $context['effective_on'],
                        'reference' => $p['reference'], 'operation_uuid' => $p['operation_uuid'], 'evidence' => $p['evidence'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $leg = $this->leg($postingId);
                $created = app(FinancialPostingRegistry::class)->record($context, $p['operation_uuid'], [$leg], $now);
                $this->need($created === ($existing === null), 'shortage_recovery_replay_conflict');
                return ['recovery_id' => $id, 'posting_id' => $postingId, 'registry_created' => $created,
                    'recovered_minor' => $leg['amount_minor'], 'closure_authorized' => false];
            });
    }

    /** Check actual persisted cash source and its independent decision. */
    public function leg(int $postingId): array
    {
        $this->need(DB::transactionLevel() > 0 && app(CustodyShortageRecoveryProposalProtection::class)->active(),
            'shortage_recovery_protection_required');
        $posting = DB::table('treasury_postings')->where('id', $postingId)->lockForUpdate()->first();
        $r = DB::table('custody_shortage_recoveries')->where('treasury_posting_id', $postingId)->lockForUpdate()->first();
        $this->need($posting && $r && $posting->kind === 'custody_shortage_recovery', 'shortage_recovery_source_missing');
        $account = DB::table('treasury_accounts')->where('id', $r->treasury_account_id)->lockForUpdate()->first();
        $session = CashSession::query()->lockForUpdate()->findOrFail($r->cash_session_id);
        $proposal = AuditEvent::query()->lockForUpdate()->findOrFail($r->proposal_id);
        $count = AuditEvent::query()->lockForUpdate()->findOrFail($r->count_approval_id);
        $p = $proposal->after;
        $minor = SignedMinor::parseUnsigned($r->amount_minor);
        $this->need($account && $account->channel === 'cash' && $account->opening_on <= $r->effective_on
            && (int) $posting->treasury_account_id === (int) $r->treasury_account_id
            && (int) $posting->posted_by === (int) $r->approved_by && $posting->effective_on === $r->effective_on
            && $posting->evidence_reference === $r->reference && SignedMinor::parse($posting->amount_minor) === $minor
            && $proposal->event === CustodyShortageRecoveryProposal::EVENT && $proposal->source === 'internal'
            && $proposal->entity_type === 'custody_shortage_recovery' && $proposal->entity_id === (string) $session->id
            && (int) $proposal->actor_user_id === (int) $r->proposed_by
            && $count->event === 'cash_session.approved' && $count->entity_type === CashSession::class
            && $count->entity_id === (string) $session->id && $session->isApproved()
            && is_array($p) && ($p['session_id'] ?? null) === (int) $session->id
            && ($p['collector_id'] ?? null) === (int) $session->user_id
            && ($p['count_approval_id'] ?? null) === (int) $count->id
            && ($p['shortage_minor'] ?? null) === $minor && ($p['account_id'] ?? null) === (int) $account->id
            && ($p['proposed_by'] ?? null) === (int) $r->proposed_by
            && ($p['reference'] ?? null) === $r->reference && ($p['evidence'] ?? null) === $r->evidence
            && ($p['operation_uuid'] ?? null) === $r->operation_uuid, 'shortage_recovery_source_mismatch');
        foreach (['cash_handover_id', 'payment_refund_id', 'cash_session_funding_id', 'treasury_transfer_id',
            'treasury_transfer_receipt_id', 'treasury_bank_fee_id', 'treasury_cash_adjustment_id',
            'treasury_bank_collection_id', 'treasury_late_cash_receipt_id', 'held_cash_payout_uuid'] as $column) {
            $this->need($posting->$column === null, 'shortage_recovery_source_mismatch');
        }
        $hash = hash('sha256', json_encode([(int) $r->id, $postingId, (int) $session->id, (int) $count->id,
            (int) $proposal->id, (int) $account->id, $minor, $r->effective_on, $r->reference,
            $r->operation_uuid, (int) $r->proposed_by, (int) $r->approved_by, $r->evidence,
            $p['original_source_hash'] ?? null], JSON_THROW_ON_ERROR));
        app(CustodyShortageSettlementPolicy::class)->reconcile($minor, [['source' => 'recovery:'.$r->id,
            'minor' => $minor, 'kind' => 'cash_recovery', 'treasury_minor' => $minor,
            'proposed_by' => (int) $r->proposed_by, 'approved_by' => (int) $r->approved_by,
            'collector_id' => (int) $session->user_id, 'evidence_hash' => $hash]]);
        return ['source_type' => 'treasury_posting', 'source_id' => (string) $postingId, 'leg' => 'treasury',
            'amount_minor' => $minor, 'currency' => 'YER', 'source_hash' => $hash];
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
