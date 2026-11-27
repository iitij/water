<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashSession;
use App\Models\User;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\CustodyShortageSettlementPolicy;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Staging-only full or residual loss classification documents. No cash posting, debt
 * recognition or final close authority. Recovery needs its own treasury source.
 */
final class CustodyShortageLossDecision
{
    public const PROPOSED = 'financial.custody_shortage_loss_proposed';
    public const APPROVED = 'financial.custody_shortage_loss_approved';

    public function propose(User $actor, int $sessionId, string $reason, string $evidence, DateTimeImmutable $now): array
    {
        $reason = trim($reason);
        $evidence = trim($evidence);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000) {
            throw new DomainException('shortage_loss_input');
        }
        return $this->execute($actor, $sessionId, $now, function ($actor, $source, $date, $recovery) use ($sessionId, $reason, $evidence): array {
            $intent = $source + ['prior_recovery' => $recovery, 'proposed_by' => (int) $actor->id, 'proposed_on' => $date,
                'reason' => $reason, 'evidence' => $evidence];
            $existing = $this->find(self::PROPOSED, $sessionId);
            if ($existing) {
                if ($existing->after != $intent || (int) $existing->actor_user_id !== (int) $actor->id) {
                    throw new DomainException('shortage_loss_proposal_conflict');
                }
                return ['created' => false, 'proposal_id' => (int) $existing->id, 'closure_authorized' => false];
            }
            $row = $this->record(self::PROPOSED, $sessionId, $actor, $intent);
            return ['created' => true, 'proposal_id' => (int) $row->id, 'closure_authorized' => false];
        });
    }

    public function approve(User $actor, int $sessionId, DateTimeImmutable $now): array
    {
        return $this->execute($actor, $sessionId, $now, function ($actor, $source, $date, $recovery) use ($sessionId): array {
            $proposal = $this->find(self::PROPOSED, $sessionId);
            if (! $proposal || ! is_array($proposal->after)) throw new DomainException('shortage_loss_proposal_missing');
            $p = $proposal->after;
            foreach ($source as $key => $value) {
                if (($p[$key] ?? null) !== $value) throw new DomainException('shortage_loss_source_changed');
            }
            if (($p['proposed_by'] ?? null) !== (int) $proposal->actor_user_id
                || ($p['proposed_on'] ?? '') > $date
                || (int) $proposal->actor_user_id === (int) $actor->id) {
                throw new DomainException('shortage_loss_independence');
            }
            if (! $this->sameRecovery($p['prior_recovery'] ?? null, $recovery)) throw new DomainException('shortage_loss_recovery_changed');
            $amount = SignedMinor::subtract($source['shortage_minor'], $recovery['recovered_minor'] ?? 0);
            $hash = $this->decisionHash($source, $p, $recovery);
            $event = ['source' => 'loss:'.$sessionId, 'minor' => $amount,
                'kind' => 'confirmed_loss', 'treasury_minor' => 0, 'proposed_by' => $p['proposed_by'],
                'approved_by' => (int) $actor->id, 'collector_id' => $source['collector_id'], 'evidence_hash' => $hash];
            $result = app(CustodyShortageSettlementPolicy::class)->reconcile($amount, [$event]);
            $decision = $event + ['proposal_id' => (int) $proposal->id, 'approved_on' => $date];
            $existing = $this->find(self::APPROVED, $sessionId);
            if ($existing && ($existing->after != $decision || (int) $existing->actor_user_id !== (int) $actor->id)) {
                throw new DomainException('shortage_loss_decision_conflict');
            }
            $row = $existing ?? $this->record(self::APPROVED, $sessionId, $actor, $decision);
            return ['created' => $existing === null, 'decision_id' => (int) $row->id,
                'source_coverage_verified' => false] + $result;
        });
    }

    /** Rebuild the decision from the protected documents at a local cutoff.
     * Only use after independent verification of original collection/count sources.
     */
    public function verifiedThrough(CashSession $session, AuditEvent $count, int $shortage, string $next, ?array $recovery = null): ?array
    {
        if (DB::transactionLevel() <= 0 || ! app(CustodyShortageDecisionProtection::class)->active()) {
            throw new DomainException('shortage_loss_protection_required');
        }
        $id = (int) $session->id;
        $until = (new DateTimeImmutable($next, new DateTimeZone('Asia/Aden')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $decision = $this->find(self::APPROVED, $id);
        if (! $decision || $decision->getRawOriginal('occurred_at') >= $until) return null;
        $proposal = $this->find(self::PROPOSED, $id);
        $source = ['session_id' => $id, 'collector_id' => (int) $session->user_id,
            'count_approval_id' => (int) $count->id, 'shortage_minor' => $shortage];
        $p = $proposal?->after;
        $d = $decision->after;
        if (! is_array($p) || ! is_array($d) || $proposal->getRawOriginal('occurred_at') < $count->getRawOriginal('occurred_at')
            || $proposal->getRawOriginal('occurred_at') > $decision->getRawOriginal('occurred_at')
            || $proposal->source !== 'internal' || $decision->source !== 'internal'
            || ($p['proposed_by'] ?? null) !== (int) $proposal->actor_user_id
            || ! is_string($p['reason'] ?? null) || mb_strlen($p['reason']) < 10 || mb_strlen($p['reason']) > 1000
            || ! is_string($p['evidence'] ?? null) || mb_strlen($p['evidence']) < 10 || mb_strlen($p['evidence']) > 2000) {
            throw new DomainException('shortage_loss_document_mismatch');
        }
        foreach ($source as $key => $value) {
            if (($p[$key] ?? null) !== $value) throw new DomainException('shortage_loss_source_changed');
        }
        $local = static fn (AuditEvent $row) => (new DateTimeImmutable($row->getRawOriginal('occurred_at'), new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        if (($p['proposed_on'] ?? null) !== $local($proposal) || ($d['approved_on'] ?? null) !== $local($decision)
            || (int) $proposal->actor_user_id === $source['collector_id']) {
            throw new DomainException('shortage_loss_document_mismatch');
        }
        if (! $this->sameRecovery($p['prior_recovery'] ?? null, $recovery)
            || ($recovery !== null && $recovery['effective_on'] > $p['proposed_on'])) {
            throw new DomainException('shortage_loss_recovery_changed');
        }
        $amount = SignedMinor::subtract($shortage, $recovery['recovered_minor'] ?? 0);
        $hash = $this->decisionHash($source, $p, $recovery);
        $event = ['source' => 'loss:'.$id, 'minor' => $amount, 'kind' => 'confirmed_loss', 'treasury_minor' => 0,
            'proposed_by' => $p['proposed_by'], 'approved_by' => (int) $decision->actor_user_id,
            'collector_id' => $source['collector_id'], 'evidence_hash' => $hash];
        $expectedDecision = $event + ['proposal_id' => (int) $proposal->id, 'approved_on' => $local($decision)];
        ksort($expectedDecision);
        ksort($d);
        if ($d !== $expectedDecision) {
            throw new DomainException('shortage_loss_document_mismatch');
        }
        $result = app(CustodyShortageSettlementPolicy::class)->reconcile($amount, [$event]);
        return ['decision_id' => (int) $decision->id, 'approved_on' => $local($decision), 'evidence_hash' => $hash] + $result;
    }

    private function execute(User $actor, int $sessionId, DateTimeImmutable $now, callable $action): array
    {
        CompletedCashRefundRequest::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now, function () use ($actor, $sessionId, $date, $action): array {
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()
                || ! app(CustodyShortageDecisionProtection::class)->active()) {
                throw new DomainException('shortage_loss_protection_required');
            }
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            CompletedCashRefundRequest::authenticated($actor);
            $actor->setRelation('roles', $actor->roles()->lockForUpdate()->get());
            app(TreasuryRegisterService::class)->authorize($actor);
            $session = CashSession::query()->lockForUpdate()->findOrFail($sessionId);
            abort_if((int) $session->user_id === (int) $actor->id, 403);
            $recovery = null;
            if (AuditEvent::query()->where('event', CustodyShortageRecoveryProposal::EVENT)
                ->where('entity_id', (string) $sessionId)->lockForUpdate()->first()
                || DB::table('custody_shortage_recoveries')->where('cash_session_id', $sessionId)->lockForUpdate()->first()) {
                $next = (new DateTimeImmutable($date, new DateTimeZone('Asia/Aden')))->modify('+1 day')->format('Y-m-d');
                $recovery = app(FinancialOutstandingPeriodEvidence::class)->verifiedPriorRecovery($session, $next);
                if ($recovery === null) throw new DomainException('shortage_loss_recovery_conflict');
            }
            $driver = $session->getConnection()->getDriverName();
            $expected = Cash::stored($session->getRawOriginal('expected_amount'), $driver, nullable: true);
            $counted = Cash::stored($session->getRawOriginal('counted_amount'), $driver, nullable: true);
            $variance = Cash::stored($session->getRawOriginal('variance_amount'), $driver, signed: true, nullable: true);
            $approval = AuditEvent::query()->where('event', 'cash_session.approved')->where('entity_type', CashSession::class)
                ->where('entity_id', (string) $sessionId)->lockForUpdate()->first();
            if (! $session->isApproved() || $session->settlement_basis !== 'physical_cash'
                || $expected === null || $counted === null || $variance === null || $variance >= 0
                || ! Cash::consistent($counted, $expected, $variance) || ! $approval
                || ! $session->approved_at || $session->approved_at->toDateString() > $date
                || (int) $session->approved_by <= 0 || (int) $session->approved_by === (int) $session->user_id
                || (int) $approval->actor_user_id !== (int) $session->approved_by
                || ($approval->after['expected_amount'] ?? null) !== SignedMinor::decimal($expected)
                || ($approval->after['counted_amount'] ?? null) !== SignedMinor::decimal($counted)
                || ($approval->after['variance_amount'] ?? null) !== SignedMinor::decimal($variance)) {
                throw new DomainException('shortage_loss_original_count_unverified');
            }
            $shortage = SignedMinor::negate($variance);
            if (($recovery['recovered_minor'] ?? 0) >= $shortage) throw new DomainException('shortage_loss_recovery_conflict');
            return $action($actor, ['session_id' => $sessionId, 'collector_id' => (int) $session->user_id,
                'count_approval_id' => (int) $approval->id, 'shortage_minor' => $shortage], $date, $recovery);
        });
    }

    private function sameRecovery(mixed $stored, ?array $expected): bool
    {
        if ($expected === null) return $stored === null;
        if (! is_array($stored)) return false;
        ksort($stored);
        ksort($expected);
        return $stored === $expected;
    }

    private function decisionHash(array $source, array $proposal, ?array $recovery): string
    {
        $payload = [$source, $proposal['proposed_by'], $proposal['proposed_on'], $proposal['reason'], $proposal['evidence']];
        if ($recovery !== null) $payload[] = $recovery;
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function find(string $event, int $id): ?AuditEvent
    {
        return AuditEvent::query()->where('event', $event)->where('entity_type', 'custody_shortage_loss')
            ->where('entity_id', (string) $id)->lockForUpdate()->first();
    }

    private function record(string $event, int $id, User $actor, array $payload): AuditEvent
    {
        return app(AuditLogger::class)->recordRequired($event, ['entity_type' => 'custody_shortage_loss',
            'entity_id' => $id, 'actor_user_id' => (int) $actor->id, 'after' => $payload, 'source' => 'internal']);
    }
}
