<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashSession;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Scan shortage settlement documents independently of the set of sessions
 * returned by the custody rollforward. Orphans and pending claims cannot
 * disappear merely because a source session is omitted from that report. */
final class CustodyShortageSettlementCoverage
{
    public function inspect(string $next): array
    {
        if (DB::transactionLevel() <= 0) throw new DomainException('shortage_coverage_requires_gate');
        $until = (new DateTimeImmutable($next, new DateTimeZone('Asia/Aden')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $issues = $rows = [];
        $pending = 0;
        $checkedPeriods = [];
        $events = [CustodyShortageRecoveryProposal::EVENT, CustodyShortageLossDecision::PROPOSED,
            CustodyShortageLossDecision::APPROVED];
        foreach (AuditEvent::query()->whereIn('event', $events)->where('occurred_at', '<', $until)
            ->orderBy('id')->lockForUpdate()->get() as $audit) {
            $payload = $audit->after;
            $sessionId = $audit->event === CustodyShortageLossDecision::APPROVED
                ? (ctype_digit((string) $audit->entity_id) ? (int) $audit->entity_id : 0)
                : ($payload['session_id'] ?? null);
            try {
                if (! is_array($payload) || ! is_int($sessionId) || $sessionId <= 0
                    || $audit->source !== 'internal' || (int) $audit->actor_user_id <= 0) {
                    throw new DomainException('shortage_coverage_document_invalid');
                }
                $session = CashSession::query()->whereKey($sessionId)->lockForUpdate()->first();
                if (! $session || ! $session->isApproved()
                    || (int) $session->user_id === (int) $audit->actor_user_id
                    || $session->getRawOriginal('approved_at') > $audit->getRawOriginal('occurred_at')) {
                    throw new DomainException('shortage_coverage_session_invalid');
                }
                if ($audit->event === CustodyShortageRecoveryProposal::EVENT) {
                    $uuid = $payload['operation_uuid'] ?? null;
                    if ($audit->entity_type !== 'custody_shortage_recovery' || ! is_string($uuid)
                        || ! CustodyShortageRecoveryProposal::matchesIdentity($audit, $sessionId, $uuid)
                        || ($payload['proposed_by'] ?? null) !== (int) $audit->actor_user_id) {
                        throw new DomainException('shortage_coverage_document_invalid');
                    }
                    $r = DB::table('custody_shortage_recoveries')->where('proposal_id', $audit->id)
                        ->where('effective_on', '<', $next)->lockForUpdate()->first();
                    if (! $r) {
                        $pending++;
                        $rows[] = ['audit_id' => (int) $audit->id, 'session_id' => $sessionId, 'status' => 'pending_recovery'];
                        continue;
                    }
                    $leg = app(CustodyShortageRecoveryWriter::class)->leg((int) $r->treasury_posting_id);
                    $registeredRows = DB::table('financial_posting_events as e')
                        ->join('financial_posting_operations as o', 'o.operation_uuid', '=', 'e.operation_uuid')
                        ->join('financial_periods as p', 'p.id', '=', 'o.period_id')
                        ->where('e.scope_key', 'water')->where('o.scope_key', 'water')->where('p.scope_key', 'water')
                        ->whereColumn('o.effective_on', '>=', 'p.start_on')->whereColumn('o.effective_on', '<', 'p.next_on')
                        ->where('e.source_type', 'treasury_posting')
                        ->where('e.source_id', $leg['source_id'])->where('e.leg', 'treasury')
                        ->select('e.*', 'o.effective_on', 'o.period_id', 'o.recorded_at_utc', 'p.next_on as period_next_on')
                        ->lockForUpdate()->get();
                    $registered = $registeredRows->first();
                    if ($registeredRows->count() !== 1 || $registered->operation_uuid !== $uuid
                        || $registered->effective_on !== $r->effective_on
                        || $registered->currency !== $leg['currency']
                        || SignedMinor::parse($registered->amount_minor) !== $leg['amount_minor']
                        || ! hash_equals($leg['source_hash'], $registered->source_hash)) {
                        throw new DomainException('shortage_coverage_registry_unmatched');
                    }
                    // A prior-period recovery also needs its own immutable registry proof.
                    $periodEnd = (new DateTimeImmutable($registered->period_next_on, new DateTimeZone('Asia/Aden')))
                        ->setTimezone(new DateTimeZone('UTC'));
                    if (new DateTimeImmutable($registered->recorded_at_utc, new DateTimeZone('UTC')) >= $periodEnd) {
                        throw new DomainException('shortage_coverage_registry_recorded_after_cutoff');
                    }
                    $periodId = (int) $registered->period_id;
                    if (! isset($checkedPeriods[$periodId])) {
                        app(FinancialPostingRegistry::class)->verifyPeriod('water', $periodId);
                        $checkedPeriods[$periodId] = true;
                    }
                    $rows[] = ['audit_id' => (int) $audit->id, 'session_id' => $sessionId,
                        'status' => 'recovery_posted', 'recovery_id' => (int) $r->id];
                } else {
                    if ($audit->entity_type !== 'custody_shortage_loss' || $audit->entity_id !== (string) $sessionId) {
                        throw new DomainException('shortage_coverage_document_invalid');
                    }
                    if ($audit->event === CustodyShortageLossDecision::PROPOSED) {
                        $decision = AuditEvent::query()->where('event', CustodyShortageLossDecision::APPROVED)
                            ->where('entity_type', 'custody_shortage_loss')->where('entity_id', (string) $sessionId)
                            ->where('occurred_at', '<', $until)->lockForUpdate()->first();
                        if (! $decision) $pending++;
                        $rows[] = ['audit_id' => (int) $audit->id, 'session_id' => $sessionId,
                            'status' => $decision ? 'loss_approved' : 'pending_loss'];
                    } else {
                        $proposal = AuditEvent::query()->where('id', $payload['proposal_id'] ?? 0)->lockForUpdate()->first();
                        if (! $proposal || $proposal->event !== CustodyShortageLossDecision::PROPOSED
                            || $proposal->entity_type !== 'custody_shortage_loss' || $proposal->entity_id !== (string) $sessionId
                            || $proposal->getRawOriginal('occurred_at') > $audit->getRawOriginal('occurred_at')
                            || (int) $proposal->actor_user_id === (int) $audit->actor_user_id) {
                            throw new DomainException('shortage_coverage_loss_proposal_unmatched');
                        }
                    }
                }
            } catch (DomainException $error) {
                $issues[] = ['code' => $error->getMessage(), 'audit_id' => (int) $audit->id];
            }
        }
        return ['rows' => $rows, 'pending_count' => $pending, 'issues' => $issues,
            'scanned_documents_match' => $issues === [], 'closure_authorized' => false];
    }
}
