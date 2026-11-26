<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive adapter for a supported, evidenced refund with a matching treasury leg.
 * Neither payment authorization nor a means to conceal an already executed
 * disbursement: unresolved/insufficient custody cases must remain incidents,
 * outside this candidate. No public activation until that policy is resolved.
 */
final class GatedRefundWriter
{
    private ?array $mutationIdentity = null;
    private ?int $completedCashReviewer = null;
    private ?array $batchCompletion = null;

    public function __construct(
        private FinancialWriteGate $gate,
        private PaymentRefundService $refunds,
        private TreasuryRegisterService $treasury,
        private FinancialLedgerEvidence $ledger,
        private TreasuryCustodyEvidence $custody,
        private TreasuryOpeningFundingEvidence $opening,
        private TreasuryCollectionEvidence $collection,
        private FinancialPostingRegistry $registry,
    ) {}

    public function record(User $actor, int $paymentId, int $accountId, string $amount,
        string $reference, string $method, string $date, string $reason, string $evidence,
        string $uuid, DateTimeImmutable $now): array
    {
        return $this->execute($actor, $paymentId, $accountId, $amount, $reference, $method, $date,
            $reason, $evidence, $uuid, $now, null, null);
    }

    /** Internal candidate: record a supported, already completed cash refund.
     * No payment instruction or permission to disburse. Independent confirmation
     * is mandatory; the ordinary refund path never receives this capability.
     */
    public function recordCompletedCashRefund(User $actor, User $reviewer, int $paymentId, int $accountId,
        string $amount, string $reference, string $date, string $reason, string $evidence,
        string $confirmation, string $uuid, DateTimeImmutable $now): array
    {
        $confirmation = trim($confirmation);
        $this->need(mb_strlen($confirmation) >= 20 && mb_strlen($confirmation) <= 2000,
            'completed_cash_refund_confirmation');
        return $this->execute($actor, $paymentId, $accountId, $amount, $reference, 'treasury_cash', $date,
            $reason, $evidence, $uuid, $now, $reviewer, $confirmation);
    }

    public function recordResolvedCashRefund(User $actor, User $reviewer, array $intent,
        string $confirmation, DateTimeImmutable $now, ?int $expectedProposalId = null): array
    {
        $confirmation = trim($confirmation);
        $this->need(mb_strlen($confirmation) >= 20 && mb_strlen($confirmation) <= 2000,
            'completed_cash_refund_confirmation');
        return $this->execute($actor, $intent['payment_id'], $intent['account_id'], $intent['amount'],
            $intent['reference'], 'treasury_cash', $intent['date'], $intent['reason'], $intent['evidence'],
            $intent['operation_uuid'], $now, $reviewer, $confirmation, true, $expectedProposalId);
    }

    public function recordBatchResolvedCashRefund(User $reviewer, int $accountId, array $proposalIds,
        string $confirmation, DateTimeImmutable $now): array
    {
        CompletedCashRefundRequest::authenticated($reviewer);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $confirmation = trim($confirmation);
        $this->need(mb_strlen($confirmation) >= 20 && mb_strlen($confirmation) <= 2000, 'completed_cash_refund_confirmation');
        $date = $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function () use ($reviewer, $accountId, $proposalIds, $confirmation, $now, $date): array {
                DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null, 'gated_refund_issuance_lock');
                $reviewer = User::query()->lockForUpdate()->findOrFail($reviewer->id);
                $this->treasury->authorize($reviewer);
                CompletedCashRefundRequest::authenticated($reviewer);
                $service = app(CompletedCashRefundRequest::class);
                $replay = $service->batchReplayLocked($reviewer, $accountId, $proposalIds, $confirmation);
                if ($replay !== null) return $replay;
                $plan = $service->batchPostingPlanLocked($reviewer, $accountId, $proposalIds, $date);
                $this->need($this->batchCompletion === null, 'completed_refund_batch_conflict');
                $this->batchCompletion = [$plan, (int) $reviewer->id, $confirmation];
                try {
                    foreach ($plan['items'] as $item) {
                        $request = \App\Models\AuditEvent::query()->where('event', CompletedCashRefundRequest::EVENT)
                            ->where('entity_id', $item['operation_uuid'])->lockForUpdate()->sole();
                        $p = $request->after;
                        $result = $this->execute(User::findOrFail($request->actor_user_id), $p['payment_id'], $accountId, $p['amount'],
                            $p['reference'], 'treasury_cash', $date, $p['reason'], $p['evidence'], $p['operation_uuid'],
                            $now, $reviewer, $confirmation, true, $item['proposal_id'], true);
                        $this->need($result['registry_created'] === true, 'completed_refund_batch_conflict');
                    }
                    $proof = $service->completeBatchPostingLocked($this, $reviewer, $plan, $confirmation);
                    return ['registry_created' => true, 'batch' => $proof,
                        'current_active_release_liquidity' => app(ActiveHeldCashRelease::class)->currentLiquidity($accountId)];
                } finally {
                    $this->batchCompletion = null;
                }
            });
    }

    public function allowsBatchCompletion(array $plan, int $reviewerId, string $confirmation): bool
    {
        return DB::transactionLevel() > 0 && $this->batchCompletion === [$plan, $reviewerId, $confirmation];
    }

    private function execute(User $actor, int $paymentId, int $accountId, string $amount,
        string $reference, string $method, string $date, string $reason, string $evidence,
        string $uuid, DateTimeImmutable $now, ?User $reviewer, ?string $confirmation, bool $resolution = false, ?int $expectedProposalId = null, bool $batch = false): array
    {
        $reference = mb_strtoupper(trim($reference));
        $write = function (array $context) use ($actor, $paymentId, $accountId, $amount, $reference, $method, $date, $reason, $evidence, $uuid, $now, $reviewer, $confirmation, $resolution, $expectedProposalId, $batch): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null, 'gated_refund_issuance_lock');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
                abort_if($actor->isDisabled(), 403);
                $candidate = Payment::query()->findOrFail($paymentId);
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
                $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
                $this->need((int) $payment->subscriber_id === (int) $subscriber->id, 'gated_refund_payment_identity');
                $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
                $channel = match ($method) { 'treasury_cash' => 'cash', 'bank_transfer' => 'bank', default => null };
                $this->need($account !== null && $channel !== null && $account->channel === $channel
                    && $account->opening_on <= $date, 'gated_refund_destination');
                $this->registered($this->opening->opening($accountId, $account->opening_on), $account->opening_on);
                $original = $this->entry(Payment::class, $paymentId, 'payment');
                $originalOp = $this->registered($this->ledger->leg((int) $original->id), $payment->payment_date->toDateString());
                if (in_array($payment->payment_method, ['check', 'bank_transfer'], true)) {
                    $doc = DB::table('treasury_bank_collections')->where('payment_id', $paymentId)->lockForUpdate()->first();
                    $posting = $doc ? DB::table('treasury_postings')->where('treasury_bank_collection_id', $doc->id)->lockForUpdate()->first() : null;
                    $this->need($posting !== null && $posting->kind === 'bank_collection', 'gated_refund_collection_unregistered');
                    $op = $this->registered($this->collection->leg((int) $posting->id), $posting->effective_on);
                    $this->need($op->operation_uuid === $originalOp->operation_uuid, 'gated_refund_collection_unregistered');
                }
                $existing = PaymentRefund::query()->where('disbursement_reference', $reference)->lockForUpdate()->first();
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $replay = $existing !== null;
                $this->need($replay === ($operation !== null), 'gated_refund_operation_conflict');
                $resolutionPlan = null;
                $intent = null;
                if ($reviewer !== null) {
                    $this->need(app(FinancialApprovalAuditProtection::class)->active(), 'financial_approval_audit_protection_missing');
                    $reviewer = User::query()->lockForUpdate()->findOrFail($reviewer->id);
                    $this->treasury->authorize($reviewer);
                    CompletedCashRefundRequest::authenticated($reviewer);
                    abort_unless(app()->environment(['testing', 'staging']), 503);
                    $this->need((int) $reviewer->id !== (int) $actor->id
                        && (int) $reviewer->id !== (int) $payment->user_id,
                        'completed_cash_refund_independence');
                    if ($payment->cash_session_id !== null) {
                        $session = \App\Models\CashSession::query()->lockForUpdate()->findOrFail($payment->cash_session_id);
                        $this->need((int) $reviewer->id !== (int) $session->user_id,
                            'completed_cash_refund_independence');
                    }
                    $this->need(app(ActiveHeldCashRelease::class)->hasAccount($accountId),
                        'completed_cash_refund_active_account_required');
                    if (! $replay) {
                        $this->need(config('financial_candidate.completed_cash_refund') === true,
                            'completed_cash_refund_disabled');
                    }
                    $intent = CompletedCashRefundRequest::intent(
                        $paymentId, $accountId, $amount, $reference, $date, $reason, $evidence, $uuid, (int) $actor->id);
                    if ($batch) {
                        $this->need($this->batchCompletion !== null && ! $replay && $resolution,
                            'completed_refund_batch_writer_required');
                        $member = collect($this->batchCompletion[0]['items'])->firstWhere('operation_uuid', $uuid);
                        $this->need(is_array($member) && $member['proposal_id'] === $expectedProposalId
                            && $member['payment_id'] === $paymentId && $member['reference'] === $reference
                            && $member['claimed_minor'] === \App\Support\FinancialAmount::parse($amount)->minor,
                            'completed_refund_batch_conflict');
                    } elseif ($resolution) {
                        $resolutionPlan = app(CompletedCashRefundRequest::class)->verifyPostingResolutionLocked(
                            $intent, $reviewer, $confirmation, $replay, $expectedProposalId);
                    } else {
                        app(CompletedCashRefundRequest::class)->verifyLocked($intent);
                    }
                } elseif (! $replay) {
                    app(CompletedCashRefundRequest::class)->assertUnreserved($uuid, $reference);
                }
                // Earlier refunds on the same receipt must have both proved legs.
                // This deliberately refuses adoption or repair of legacy sources.
                foreach (PaymentRefund::query()->where('payment_id', $paymentId)->orderBy('id')->lockForUpdate()->get() as $prior) {
                    $op = $this->verifyRefund($prior);
                    if ($existing !== null && (int) $prior->id === (int) $existing->id) {
                        $this->need($op->operation_uuid === $uuid, 'gated_refund_operation_conflict');
                    }
                }
                if ($replay) {
                    $this->need((int) $existing->payment_id === $paymentId && (int) $existing->recorded_by === (int) $actor->id
                        && $existing->reason === trim($reason) && $existing->evidence === trim($evidence),
                        'gated_refund_replay_conflict');
                    $posted = $this->posting((int) $existing->id);
                    $this->need((int) $posted->treasury_account_id === $accountId && (int) $posted->posted_by === (int) $actor->id,
                        'gated_refund_replay_conflict');
                    $this->verifyCompletedEvidence($existing, (int) $posted->id, $accountId, $uuid, $reviewer, $confirmation);
                }
                $balance = app(TransactionService::class);
                $before = $balance->accountBalanceMinor($subscriber);
                $this->need($this->mutationIdentity === null && DB::transactionLevel() > 0, 'gated_refund_mutation_scope');
                $this->mutationIdentity = [$paymentId, $reference, $accountId, (int) $actor->id];
                $this->completedCashReviewer = $reviewer === null ? null : (int) $reviewer->id;
                try {
                    $refund = $this->refunds->record($payment, $actor, $amount, $reference, $method, $date, $reason, $evidence, $this);
                    $posting = $this->treasury->post($actor, $accountId, 'payment_refund', (int) $refund->id, $this);
                } finally {
                    $this->mutationIdentity = null;
                    $this->completedCashReviewer = null;
                }
                $entry = $this->entry(PaymentRefund::class, (int) $refund->id, 'payment_refund');
                $minor = AccountMoney::stored($refund);
                $legs = [$this->ledger->leg((int) $entry->id), $this->custody->leg((int) $posting['id'])];
                $this->need($minor > 0 && $legs[0]['amount_minor'] === -$minor && $legs[1]['amount_minor'] === -$minor
                    && $refund->disbursed_on->toDateString() === $context['effective_on'] && $posting['effective_on'] === $context['effective_on']
                    && (int) $posting['treasury_account_id'] === $accountId && (int) $posting['posted_by'] === (int) $actor->id
                    && $balance->accountBalanceMinor($subscriber) === SignedMinor::add($before, $replay ? 0 : -$minor),
                    'gated_refund_effect_mismatch');
                $created = $this->registry->record($context, $uuid, $legs, $now);
                $this->need($created === ! $replay, 'gated_refund_operation_conflict');
                $result = ['refund' => $refund, 'posting' => $posting, 'transaction_id' => (int) $entry->id, 'registry_created' => $created];
                if ($reviewer !== null) {
                    if ($created) {
                        app(AuditLogger::class)->recordRequired('financial.completed_cash_refund', [
                            'entity_type' => PaymentRefund::class, 'entity_id' => $refund->id,
                            'actor_user_id' => $reviewer->id,
                            'after' => $this->completedProof($refund, (int) $posting['id'], $accountId, $uuid, $confirmation),
                            'source' => 'internal']);
                    }
                    $this->verifyCompletedEvidence($refund, (int) $posting['id'], $accountId, $uuid, $reviewer, $confirmation);
                    if ($resolution && $created && ! $batch) {
                        $this->need(is_array($resolutionPlan), 'completed_refund_resolution_conflict');
                        app(CompletedCashRefundRequest::class)->completePostingResolutionLocked($intent, $reviewer, $resolutionPlan);
                    }
                    if (! $batch) $result['current_active_release_liquidity'] = app(ActiveHeldCashRelease::class)->currentLiquidity($accountId);
                }
                return $result;
            };
        if ($batch) return $write(FinancialWriteGate::currentContext());
        return $this->gate->withinOpenPeriod('water', $date, $now, $write);
    }

    public function allowsRecord(int $paymentId, string $reference, int $actorId): bool
    {
        return DB::transactionLevel() > 0 && $this->mutationIdentity !== null
            && $this->mutationIdentity[0] === $paymentId && $this->mutationIdentity[1] === $reference
            && $this->mutationIdentity[3] === $actorId;
    }

    public function allowsPosting(PaymentRefund $refund, int $accountId, int $actorId): bool
    {
        return DB::transactionLevel() > 0 && $this->mutationIdentity === [
            (int) $refund->payment_id, $refund->disbursement_reference, $accountId, $actorId,
        ] && (int) $refund->recorded_by === $actorId;
    }

    public function allowsCompletedCashPosting(PaymentRefund $refund, int $accountId, int $actorId): bool
    {
        return $this->completedCashReviewer !== null && $this->completedCashReviewer !== $actorId
            && $refund->method === 'treasury_cash' && $this->allowsPosting($refund, $accountId, $actorId);
    }

    private function completedProof(PaymentRefund $refund, int $postingId, int $accountId, string $uuid, string $confirmation): array
    {
        return ['refund_id' => (int) $refund->id, 'payment_id' => (int) $refund->payment_id,
            'recorded_by' => (int) $refund->recorded_by, 'account_id' => $accountId,
            'posting_id' => $postingId, 'operation_uuid' => $uuid,
            'amount_minor' => AccountMoney::stored($refund), 'reference' => $refund->disbursement_reference,
            'effective_on' => $refund->disbursed_on->toDateString(), 'confirmation' => $confirmation];
    }

    private function verifyCompletedEvidence(PaymentRefund $refund, int $postingId, int $accountId, string $uuid,
        ?User $reviewer, ?string $confirmation): void
    {
        $rows = \App\Models\AuditEvent::query()->where('event', 'financial.completed_cash_refund')
            ->where('entity_type', PaymentRefund::class)->where('entity_id', (string) $refund->id)->lockForUpdate()->get();
        if ($reviewer === null) {
            $this->need($rows->isEmpty(), 'completed_cash_refund_replay_conflict');
            return;
        }
        $this->need($rows->count() === 1 && (int) $rows->first()->actor_user_id === (int) $reviewer->id,
            'completed_cash_refund_replay_conflict');
        $expected = $this->completedProof($refund, $postingId, $accountId, $uuid, $confirmation);
        $actual = $rows->first()->after;
        $this->need(is_array($actual) && count($actual) === count($expected), 'completed_cash_refund_replay_conflict');
        foreach ($expected as $key => $value) {
            $this->need(($actual[$key] ?? null) === $value, 'completed_cash_refund_replay_conflict');
        }
    }

    /** Exact source and approval identity; never acquire a scope lock from a business-lock path. */
    public function verifyIncidentPosting(array $intent): void
    {
        $this->need(DB::transactionLevel() > 0, 'held_cash_reservation_context');
        $this->need(app(FinancialApprovalAuditProtection::class)->active(), 'financial_approval_audit_protection_missing');
        $refund = PaymentRefund::query()->where('disbursement_reference', $intent['reference'])->lockForUpdate()->sole();
        $minor = \App\Support\FinancialAmount::parse($intent['amount'])->minor;
        $this->need((int) $refund->payment_id === $intent['payment_id']
            && (int) $refund->recorded_by === $intent['requested_by']
            && $refund->method === 'treasury_cash' && AccountMoney::stored($refund) === $minor
            && $refund->disbursed_on->toDateString() === $intent['date']
            && $refund->reason === $intent['reason'] && $refund->evidence === $intent['evidence'],
            'completed_cash_refund_incident_unresolved');
        $posting = $this->posting((int) $refund->id);
        $entry = $this->entry(PaymentRefund::class, (int) $refund->id, 'payment_refund');
        $this->need((int) $posting->treasury_account_id === $intent['account_id']
            && (int) $posting->posted_by === $intent['requested_by'], 'completed_cash_refund_incident_unresolved');
        foreach ([$this->ledger->leg((int) $entry->id), $this->custody->leg((int) $posting->id)] as $leg) {
            $this->need($leg['amount_minor'] === -$minor
                && $this->registered($leg, $intent['date'], false)->operation_uuid === $intent['operation_uuid'],
                'completed_cash_refund_incident_unresolved');
        }
        $this->need(DB::table('financial_posting_events')->where('operation_uuid', $intent['operation_uuid'])
            ->lockForUpdate()->get()->count() === 2, 'completed_cash_refund_incident_unresolved');
        $audit = \App\Models\AuditEvent::query()->where('event', 'financial.completed_cash_refund')
            ->where('entity_type', PaymentRefund::class)->where('entity_id', (string) $refund->id)->lockForUpdate()->sole();
        $confirmation = $audit->after['confirmation'] ?? null;
        $this->need(is_string($confirmation) && mb_strlen(trim($confirmation)) >= 20
            && $audit->source === 'internal' && (int) $audit->actor_user_id > 0
            && (int) $audit->actor_user_id !== $intent['requested_by'], 'completed_cash_refund_incident_unresolved');
        $payment = Payment::query()->lockForUpdate()->findOrFail($intent['payment_id']);
        $this->need((int) $audit->actor_user_id !== (int) $payment->user_id, 'completed_cash_refund_incident_unresolved');
        if ($payment->cash_session_id !== null) {
            $session = \App\Models\CashSession::query()->lockForUpdate()->findOrFail($payment->cash_session_id);
            $this->need((int) $audit->actor_user_id !== (int) $session->user_id, 'completed_cash_refund_incident_unresolved');
        }
        $expected = $this->completedProof($refund, (int) $posting->id, $intent['account_id'], $intent['operation_uuid'], $confirmation);
        $actual = $audit->after;
        $this->need(is_array($actual) && count($actual) === count($expected), 'completed_cash_refund_incident_unresolved');
        foreach ($expected as $key => $value) {
            $this->need(($actual[$key] ?? null) === $value, 'completed_cash_refund_incident_unresolved');
        }
    }

    /** Both legs must remain protected even when the candidate switch is off. */
    public static function hasRegisteredLeg(PaymentRefund $refund): bool
    {
        $events = DB::table('financial_posting_events')->where('scope_key', 'water');
        $postingIds = DB::table('treasury_postings')->where('payment_refund_id', $refund->id)->pluck('id');
        foreach ($postingIds as $id) {
            if ((clone $events)->where('source_type', 'treasury_posting')->where('leg', 'treasury')
                ->where('source_id', (string) $id)->exists()) return true;
        }
        $ledgerIds = Transaction::withTrashed()->where('reference_type', PaymentRefund::class)
            ->where('reference_id', $refund->id)->where('type', 'payment_refund')->pluck('id');
        foreach ($ledgerIds as $id) {
            if ((clone $events)->where('source_type', 'ledger_transaction')->where('leg', 'subscriber')
                ->where('source_id', (string) $id)->exists()) return true;
        }
        return false;
    }

    private function verifyRefund(PaymentRefund $refund): object
    {
        $date = $refund->disbursed_on->toDateString();
        $entry = $this->entry(PaymentRefund::class, (int) $refund->id, 'payment_refund');
        $posting = $this->posting((int) $refund->id);
        $op = $this->registered($this->ledger->leg((int) $entry->id), $date);
        $other = $this->registered($this->custody->leg((int) $posting->id), $date);
        $this->need($op->operation_uuid === $other->operation_uuid
            && DB::table('financial_posting_events')->where('operation_uuid', $op->operation_uuid)->count() === 2,
            'gated_refund_prior_unregistered');
        return $op;
    }

    private function posting(int $id): object
    {
        $row = DB::table('treasury_postings')->where('payment_refund_id', $id)->lockForUpdate()->first();
        $this->need($row !== null && $row->kind === 'payment_refund', 'gated_refund_prior_unregistered');
        return $row;
    }

    private function entry(string $class, int $id, string $type): Transaction
    {
        return Transaction::query()->where('reference_type', $class)->where('reference_id', $id)
            ->where('type', $type)->lockForUpdate()->sole();
    }

    private function registered(array $leg, string $date, bool $verifyPeriod = true): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_refund_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date, 'gated_refund_prerequisite_unregistered');
        if ($verifyPeriod) $this->registry->verifyPeriod('water', (int) $op->period_id);
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
