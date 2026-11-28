<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashSession;
use App\Models\User;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Proposal of a factual cash recovery (full or partial). Not a receipt or cash posting. */
final class CustodyShortageRecoveryProposal
{
    public const EVENT = 'financial.custody_shortage_recovery_proposed';

    /** Legacy first proposals retain their numeric identity; later installments
     * have a distinct session:UUID identity protected by the same audit guards. */
    public static function forSession(int $sessionId): \Illuminate\Database\Eloquent\Builder
    {
        return AuditEvent::query()->where('event', self::EVENT)->where('entity_type', 'custody_shortage_recovery')
            ->where(fn ($q) => $q->where('entity_id', (string) $sessionId)->orWhere('entity_id', 'like', $sessionId.':%'));
    }

    public static function matchesIdentity(AuditEvent $row, int $sessionId, string $uuid): bool
    {
        return $row->entity_id === (string) $sessionId || $row->entity_id === $sessionId.':'.$uuid;
    }

    public function propose(User $actor, int $sessionId, int $accountId, string $reference, string $evidence,
        string $uuid, DateTimeImmutable $now, ?int $recoveryMinor = null): array
    {
        CompletedCashRefundRequest::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        if ($sessionId <= 0 || $accountId <= 0 || mb_strlen($reference) < 3 || mb_strlen($reference) > 150
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid)) {
            throw new DomainException('shortage_recovery_proposal_input');
        }
        $local = $now->setTimezone(new DateTimeZone('Asia/Aden'));
        $date = $local->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now,
            function () use ($actor, $sessionId, $accountId, $reference, $evidence, $uuid, $date, $local, $recoveryMinor): array {
                if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()
                    || ! app(CustodyShortageRecoveryProposalProtection::class)->active()
                || ! app(CustodyShortagePeriodProtection::class)->active()) {
                    throw new DomainException('shortage_recovery_protection_required');
                }
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                CompletedCashRefundRequest::authenticated($actor);
                $actor->setRelation('roles', $actor->roles()->lockForUpdate()->get());
                app(TreasuryRegisterService::class)->authorize($actor);
                $session = CashSession::query()->lockForUpdate()->findOrFail($sessionId);
                abort_if((int) $session->user_id === (int) $actor->id, 403);
                $source = app(FinancialOutstandingPeriodEvidence::class)
                    ->verifiedShortageSource($session, $local->modify('+1 day')->format('Y-m-d'));
                $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
                if (! $account || $account->channel !== 'cash' || $account->opening_on > $date) {
                    throw new DomainException('shortage_recovery_account_invalid');
                }
                if (AuditEvent::query()->where('entity_type', 'custody_shortage_loss')
                    ->where('entity_id', (string) $sessionId)
                    ->whereIn('event', [CustodyShortageLossDecision::PROPOSED, CustodyShortageLossDecision::APPROVED])
                    ->lockForUpdate()->first()) {
                    throw new DomainException('shortage_recovery_loss_conflict');
                }
                $prior = app(FinancialOutstandingPeriodEvidence::class)->verifiedPriorRecovery($session, $local->modify('+1 day')->format('Y-m-d'));
                $remaining = SignedMinor::subtract($source['shortage_minor'], $prior['recovered_minor'] ?? 0);
                // An explicit replay retains its original amount, even after posting.
                $amount = $recoveryMinor ?? $remaining;
                $intent = $source + ['recovery_minor' => $amount, 'account_id' => $accountId, 'reference' => $reference,
                    'evidence' => $evidence, 'operation_uuid' => $uuid, 'proposed_on' => $date,
                    'proposed_by' => (int) $actor->id];
                foreach (AuditEvent::query()->where('event', self::EVENT)->orderBy('id')->lockForUpdate()->get() as $row) {
                    $p = $row->after;
                    if (! is_array($p)) throw new DomainException('shortage_recovery_proposal_conflict');
                    if (($p['operation_uuid'] ?? null) === $uuid) {
                        if ($recoveryMinor === null) $intent['recovery_minor'] = $p['recovery_minor'] ?? $p['shortage_minor'];
                        if ($p != $intent || (int) $row->actor_user_id !== (int) $actor->id
                            || $row->entity_type !== 'custody_shortage_recovery' || ! self::matchesIdentity($row, $sessionId, $uuid)) {
                            throw new DomainException('shortage_recovery_proposal_conflict');
                        }
                        return ['created' => false, 'proposal_id' => (int) $row->id,
                            'cash_posted' => DB::table('custody_shortage_recoveries')->where('proposal_id', $row->id)->exists()];
                    }
                    if (($p['reference'] ?? null) === $reference) throw new DomainException('shortage_recovery_proposal_conflict');
                    if (($p['session_id'] ?? null) === $sessionId
                        && ! DB::table('custody_shortage_recoveries')->where('proposal_id', $row->id)->exists()) {
                        throw new DomainException('shortage_recovery_pending_proposal');
                    }
                }
                if ($amount <= 0 || $amount > $remaining) throw new DomainException('shortage_recovery_amount_invalid');
                if (DB::table('treasury_accounts')->where('opening_reference', $reference)->exists()
                    || DB::table('treasury_postings')->where('evidence_reference', $reference)->exists()
                    || DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->exists()) {
                    throw new DomainException('shortage_recovery_proposal_conflict');
                }
                $row = app(AuditLogger::class)->recordRequired(self::EVENT, [
                    'entity_type' => 'custody_shortage_recovery', 'entity_id' => self::forSession($sessionId)->exists() ? $sessionId.':'.$uuid : $sessionId,
                    'actor_user_id' => (int) $actor->id, 'after' => $intent, 'source' => 'internal']);
                return ['created' => true, 'proposal_id' => (int) $row->id, 'cash_posted' => false];
            });
    }
}
