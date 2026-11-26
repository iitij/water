<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Support\FinancialAmount;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Staging-only two-account workflow for facts, not permission to disburse. */
final class CompletedCashRefundRequest
{
    public const EVENT = 'financial.completed_cash_refund_requested';
    public const CLOSED = 'financial.completed_cash_refund_closed';
    public const RESOLUTION_PROPOSED = 'financial.completed_cash_refund_resolution_proposed';
    public const RESOLUTION_REVISED = 'financial.completed_cash_refund_resolution_revised';
    public const BATCH_APPROVED = 'financial.completed_cash_refund_batch_approved';
    public const DOCUMENT_RECORDED = 'financial.completed_cash_refund_document_recorded';
    public const DOCUMENT_REVIEWED = 'financial.completed_cash_refund_document_reviewed';
    public const SOURCE_ATTESTED = 'financial.completed_cash_refund_source_attested';
    public const RESOLUTION_APPROVED = 'financial.completed_cash_refund_resolution_approved';

    /** Unsigned historical algorithms remain exercisable only in isolated synthetic QA.
     * Neither deployment configuration nor a caller may enable them in staging.
     */
    public static function requireSignedNewSettlement(): void
    {
        if (app()->environment('testing')
            && config('financial_candidate.qa_legacy_unsigned_refund_fixture') === true
            && ((DB::getDriverName() === 'sqlite' && DB::getDatabaseName() === ':memory:')
                || (DB::getDriverName() === 'mysql' && DB::getDatabaseName() === 'water_test'))) {
            return;
        }
        throw new DomainException('completed_refund_signed_source_required');
    }

    public static function authenticated(User $actor): void
    {
        $authenticated = auth('sanctum')->user();
        abort_unless($authenticated instanceof User && $authenticated->is($actor)
            && ! $actor->isDisabled() && ! $authenticated->isDisabled(), 403);
    }

    public static function intent(int $paymentId, int $accountId, string $amount, string $reference,
        string $date, string $reason, string $evidence, string $uuid, int $actorId): array
    {
        $reference = mb_strtoupper(trim($reference));
        $reason = trim($reason);
        $evidence = trim($evidence);
        if ($paymentId <= 0 || $accountId <= 0 || $actorId <= 0
            || ! preg_match('/\A\d{1,10}(?:\.\d{1,2})?\z/', $amount)
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid)
            || mb_strlen($reference) < 3 || mb_strlen($reference) > 150
            || mb_strlen($reason) < 10 || mb_strlen($reason) > 1000
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000) {
            throw new DomainException('completed_cash_refund_request_input');
        }
        $money = FinancialAmount::parse($amount);
        if ($money->minor <= 0) throw new DomainException('completed_cash_refund_request_input');
        return ['payment_id' => $paymentId, 'account_id' => $accountId, 'amount' => $money->decimal(),
            'reference' => $reference, 'date' => $date, 'reason' => $reason, 'evidence' => $evidence,
            'operation_uuid' => $uuid, 'requested_by' => $actorId];
    }

    public function submit(User $actor, array $intent, DateTimeImmutable $now): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        if (config('financial_candidate.completed_cash_refund') !== true) throw new DomainException('completed_cash_refund_disabled');
        $intent = self::intent($intent['payment_id'], $intent['account_id'], $intent['amount'], $intent['reference'],
            $intent['date'], $intent['reason'], $intent['evidence'], $intent['operation_uuid'], (int) $actor->id);
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $intent['date'], $now, function () use ($actor, $intent): array {
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new DomainException('gated_refund_issuance_lock');
            }
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            self::authenticated($actor);
            app(TreasuryRegisterService::class)->authorize($actor);
            $this->guard();
            $payment = Payment::query()->lockForUpdate()->findOrFail($intent['payment_id']);
            $account = DB::table('treasury_accounts')->where('id', $intent['account_id'])->lockForUpdate()->first();
            abort_unless((int) $payment->user_id !== (int) $actor->id, 403);
            if ($account === null || $account->channel !== 'cash' || $account->opening_on > $intent['date']
                || ! app(ActiveHeldCashRelease::class)->hasAccount($intent['account_id'])) {
                throw new DomainException('completed_cash_refund_active_account_required');
            }
            $existing = $this->find($intent['operation_uuid'], true);
            if ($existing !== null) {
                $this->assertIntent($existing, $intent);
                return ['operation_uuid' => $intent['operation_uuid'], 'created' => false]
                    + $this->status($existing);
            }
            $this->assertUnreserved($intent['operation_uuid'], $intent['reference']);
            if (PaymentRefund::query()->where('disbursement_reference', $intent['reference'])->exists()
                || DB::table('financial_posting_operations')->where('operation_uuid', $intent['operation_uuid'])->exists()) {
                throw new DomainException('completed_cash_refund_request_conflict');
            }
            app(AuditLogger::class)->recordRequired(self::EVENT, [
                'entity_type' => 'completed_cash_refund_request', 'entity_id' => $intent['operation_uuid'],
                'actor_user_id' => $actor->id, 'after' => $intent, 'source' => 'internal']);
            return ['operation_uuid' => $intent['operation_uuid'], 'created' => true,
                'status' => 'pending', 'incident_unresolved' => true];
        });
    }

    public function inspect(User $actor, string $uuid): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        app(TreasuryRegisterService::class)->authorize($actor);
        return DB::transaction(function () use ($uuid): array {
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new DomainException('gated_refund_issuance_lock');
            }
            $this->guard();
            $row = $this->find($uuid, true);
            abort_unless($row !== null, 404);
            $p = $row->after;
            if (! is_array($p)) throw new DomainException('completed_cash_refund_request_conflict');
            $this->assertIntent($row, self::intent($p['payment_id'], $p['account_id'], $p['amount'], $p['reference'],
                $p['date'], $p['reason'], $p['evidence'], $uuid, (int) $row->actor_user_id));
            return ['request' => $p] + $this->status($row);
        });
    }

    /** Close the review of the submitted document, never declare that cash
     * did not move. Rejected/withdrawn assertions remain unresolved incidents.
     * A separate independently reviewed correction workflow is required.
     */
    public function close(User $actor, string $uuid, string $decision, string $reason, DateTimeImmutable $now): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $reason = trim($reason);
        if (! in_array($decision, ['rejected', 'withdrawn'], true)
            || mb_strlen($reason) < 20 || mb_strlen($reason) > 2000) {
            throw new DomainException('completed_cash_refund_decision_input');
        }
        $request = $this->find($uuid, false);
        abort_unless($request !== null, 404);
        // A review decision is dated today, not backdated to the asserted cash event.
        $date = $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now,
            function () use ($actor, $uuid, $decision, $reason, $date): array {
                if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new DomainException('gated_refund_issuance_lock');
                }
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                self::authenticated($actor);
                app(TreasuryRegisterService::class)->authorize($actor);
                $this->guard();
                $row = $this->find($uuid, true);
                abort_unless($row !== null, 404);
                $p = $row->after;
                $this->assertIntent($row, self::intent($p['payment_id'], $p['account_id'], $p['amount'],
                    $p['reference'], $p['date'], $p['reason'], $p['evidence'], $uuid, (int) $row->actor_user_id));
                $payment = Payment::query()->lockForUpdate()->findOrFail($p['payment_id']);
                $isRequester = (int) $row->actor_user_id === (int) $actor->id;
                if (($decision === 'withdrawn' && ! $isRequester)
                    || ($decision === 'rejected' && ($isRequester || (int) $payment->user_id === (int) $actor->id))) {
                    throw new DomainException('completed_cash_refund_decision_independence');
                }
                if ($decision === 'rejected' && $payment->cash_session_id !== null) {
                    $session = \App\Models\CashSession::query()->lockForUpdate()->findOrFail($payment->cash_session_id);
                    if ((int) $session->user_id === (int) $actor->id) {
                        throw new DomainException('completed_cash_refund_decision_independence');
                    }
                }
                $status = $this->status($row);
                if ($status['operation_exists']) throw new DomainException('completed_cash_refund_already_posted');
                $existing = $this->decision($row);
                if ($existing !== null) {
                    if ((int) $existing->actor_user_id !== (int) $actor->id
                        || $existing->after['decision'] !== $decision || $existing->after['reason'] !== $reason) {
                        throw new DomainException('completed_cash_refund_decision_conflict');
                    }
                    return ['created' => false] + $status;
                }
                app(AuditLogger::class)->recordRequired(self::CLOSED, [
                    'entity_type' => 'completed_cash_refund_request', 'entity_id' => $uuid,
                    'actor_user_id' => $actor->id, 'source' => 'internal',
                    'after' => ['request_id' => (int) $row->id, 'operation_uuid' => $uuid,
                        'decision' => $decision, 'reason' => $reason, 'decided_on' => $date,
                        'incident_unresolved' => true],
                ]);
                return ['created' => true] + $this->status($row);
            });
    }

    /** Record a non-operative investigation proposal; never release cash here. */
    public function proposeResolution(User $actor, string $uuid, int $countId, string $classification,
        string $evidence, DateTimeImmutable $now, ?int $supersedesId = null): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $evidence = trim($evidence);
        if ($countId <= 0 || ($supersedesId !== null && $supersedesId <= 0) || ! in_array($classification, ['no_disbursement', 'cash_returned', 'posting_required'], true)
            || mb_strlen($evidence) < 20 || mb_strlen($evidence) > 2000) {
            throw new DomainException('completed_refund_resolution_input');
        }
        $date = $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now,
            fn () => $this->proposeResolutionLocked($actor, $uuid, $countId, $classification, $evidence, $date, $supersedesId));
    }

    /** Private only: aggregate variance may be supplied solely by the complete group writer. */
    private function proposeResolutionLocked(User $actor, string $uuid, int $countId, string $classification,
        string $evidence, string $date, ?int $supersedesId, ?int $aggregateVariance = null, ?string $groupRevisionHash = null): array
    {
        FinancialWriteGate::currentContext();
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new DomainException('gated_refund_issuance_lock');
        }
        $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
        self::authenticated($actor);
        app(TreasuryRegisterService::class)->authorize($actor);
        $this->guard();
        $row = $this->find($uuid, true);
        abort_unless($row !== null, 404);
        $p = $row->after;
        $this->assertIntent($row, self::intent($p['payment_id'], $p['account_id'], $p['amount'],
            $p['reference'], $p['date'], $p['reason'], $p['evidence'], $uuid, (int) $row->actor_user_id));
        $decision = $this->decision($row);
        if ($decision === null || DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first()) {
            throw new DomainException('completed_refund_resolution_closed_request_required');
        }
        $payment = Payment::query()->lockForUpdate()->findOrFail($p['payment_id']);
        $excluded = [(int) $row->actor_user_id, (int) $payment->user_id];
        if ($payment->cash_session_id !== null) {
            $excluded[] = (int) \App\Models\CashSession::query()->lockForUpdate()->findOrFail($payment->cash_session_id)->user_id;
        }
        if (in_array((int) $actor->id, $excluded, true)) {
            throw new DomainException('completed_refund_resolution_independence');
        }
        $account = DB::table('treasury_accounts')->where('id', $p['account_id'])->lockForUpdate()->first();
        $count = DB::table('treasury_cash_counts')->where('id', $countId)->lockForUpdate()->first();
        $review = DB::table('treasury_cash_count_reviews')->where('treasury_cash_count_id', $countId)->lockForUpdate()->first();
        if ($account === null || $account->channel !== 'cash' || $count === null || $review === null
            || (int) $count->treasury_account_id !== $p['account_id'] || ! (bool) $review->accepted
            || (int) $count->counted_by === (int) $review->reviewed_by
            || in_array((int) $count->counted_by, $excluded, true)
            || in_array((int) $review->reviewed_by, $excluded, true)) {
            throw new DomainException('completed_refund_resolution_count_evidence');
        }
        $observation = AuditEvent::query()->where('event', 'treasury.cash_count_recorded')
            ->where('entity_type', 'treasury_cash_count')->where('entity_id', (string) $countId)->lockForUpdate()->get();
        $reviewAudit = AuditEvent::query()->where('event', 'treasury.cash_count_reviewed')
            ->where('entity_type', 'treasury_cash_count_review')->where('entity_id', (string) $review->id)->lockForUpdate()->get();
        if ($observation->count() !== 1 || $reviewAudit->count() !== 1) {
            throw new DomainException('completed_refund_resolution_count_evidence');
        }
        $o = $observation->first();
        $r = $reviewAudit->first();
        $counted = \App\Support\TreasuryMoney::document($count->counted_minor);
        $recorded = \App\Support\TreasuryMoney::signed($count->recorded_minor);
        $variance = \App\Support\TreasuryMoney::variance($counted, $recorded);
        if ((int) $o->id <= (int) $decision->id || (int) $r->id <= (int) $o->id
            || (int) $o->actor_user_id !== (int) $count->counted_by
            || (int) $r->actor_user_id !== (int) $review->reviewed_by
            || ($o->after['ledger_hash'] ?? null) !== $count->ledger_hash
            || ($o->after['counted_minor'] ?? null) !== $counted
            || ($o->after['recorded_minor'] ?? null) !== $recorded
            || ($o->after['variance_minor'] ?? null) !== $variance
            || $variance !== \App\Support\TreasuryMoney::signed($count->variance_minor)
            || ($o->after['reference'] ?? null) !== $count->reference
            || ($o->after['evidence'] ?? null) !== $count->evidence
            || (int) ($r->after['treasury_cash_count_id'] ?? 0) !== $countId
            || ($r->after['accepted'] ?? null) !== true
            || ($r->after['evidence'] ?? null) !== $review->evidence
            || ($r->after['financial_adjustment_created'] ?? null) !== false) {
            throw new DomainException('completed_refund_resolution_count_evidence');
        }
        $proof = ['request_id' => (int) $row->id, 'decision_id' => (int) $decision->id,
            'operation_uuid' => $uuid, 'account_id' => $p['account_id'], 'payment_id' => $p['payment_id'],
            'claimed_amount' => $p['amount'], 'reference' => $p['reference'],
            'count_id' => $countId, 'review_id' => (int) $review->id, 'count_audit_id' => (int) $o->id,
            'review_audit_id' => (int) $r->id, 'counted_minor' => $counted,
            'recorded_minor' => $recorded, 'variance_minor' => $variance, 'ledger_hash' => $count->ledger_hash,
            'classification' => $classification, 'evidence' => $evidence, 'proposed_on' => $date,
            'incident_unresolved' => true];
        if ($supersedesId !== null && $aggregateVariance === null
            && $this->unresolvedForAccount($p['account_id'])['count'] > 1) {
            throw new DomainException('completed_refund_group_revision_required');
        }
        if (($aggregateVariance === null) !== ($groupRevisionHash === null)) {
            throw new DomainException('completed_refund_group_revision_selection');
        }
        $chain = $this->proposalChain($row, $decision);
        $old = $chain === [] ? null : end($chain);
        if ($this->verifiedResolution($row, $decision) !== null) {
            throw new DomainException('completed_refund_resolution_conflict');
        }
        if ($supersedesId !== null) {
            if ($old === null) throw new DomainException('completed_refund_resolution_conflict');
            // Idempotent revision retry binds the original predecessor.
            $previous = $old;
            if (($old->after['supersedes_id'] ?? null) === $supersedesId) {
                $previous = $chain[count($chain) - 2];
            } elseif ((int) $old->id !== $supersedesId) {
                throw new DomainException('completed_refund_resolution_conflict');
            }
            $proof['supersedes_id'] = (int) $previous->id;
            $proof['supersedes_hash'] = $this->proposalHash($previous->after);
            if ($groupRevisionHash !== null) $proof['group_revision_hash'] = $groupRevisionHash;
            if ((int) $old->id === $supersedesId) {
                if ($countId <= $old->after['count_id'] || (int) $o->id <= (int) $old->id) {
                    throw new DomainException('completed_refund_resolution_stale_count');
                }
                // A later accepted, unchanged observation supersedes the
                // previous evidence; neither record can authorize spending.
                $expected = $aggregateVariance ?? ($classification === 'posting_required' ? -FinancialAmount::parse($p['amount'])->minor : 0);
                app(TreasuryCashCountService::class)->assertFreshObservationVariance($p['account_id'], $countId, $date, $expected);
                if ($variance !== $expected || app(HeldCashRecordedLiquidityEvidence::class)->balance($p['account_id'], $date) !== $recorded) {
                    throw new DomainException('completed_refund_resolution_stale_count');
                }
                $revision = app(AuditLogger::class)->recordRequired(self::RESOLUTION_REVISED, [
                    'entity_type' => 'completed_cash_refund_request', 'entity_id' => $uuid.':'.$countId,
                    'actor_user_id' => $actor->id, 'source' => 'internal', 'after' => $proof]);
                $this->proposalChain($row, $decision);
                return ['created' => true, 'proposal_id' => (int) $revision->id,
                    'resolution_proposal' => $proof, 'incident_unresolved' => true];
            }
        }
        if ($old !== null) {
            $actual = $old->after;
            if ((int) $old->actor_user_id !== (int) $actor->id || $old->entity_type !== 'completed_cash_refund_request'
                || $old->source !== 'internal' || ! is_array($actual) || count($actual) !== count($proof)) {
                throw new DomainException('completed_refund_resolution_conflict');
            }
            // MySQL JSON storage may reorder object keys. Keep strict
            // field identity and type checks independent of key order.
            foreach ($proof as $key => $value) {
                if (! array_key_exists($key, $actual) || $actual[$key] !== $value) {
                    throw new DomainException('completed_refund_resolution_conflict');
                }
            }
            return ['created' => false, 'proposal_id' => (int) $old->id, 'resolution_proposal' => $proof, 'incident_unresolved' => true];
        }
        $created = app(AuditLogger::class)->recordRequired(self::RESOLUTION_PROPOSED, [
            'entity_type' => 'completed_cash_refund_request', 'entity_id' => $uuid,
            'actor_user_id' => $actor->id, 'source' => 'internal', 'after' => $proof]);
        return ['created' => true, 'proposal_id' => (int) $created->id, 'resolution_proposal' => $proof, 'incident_unresolved' => true];
    }

    /**
     * Renew a complete unresolved group on one new accepted count. Classifications
     * are deliberately unchanged; a different factual conclusion needs a separate
     * reviewed workflow. No financial posting or release is performed here.
     */
    public function renewResolutionGroup(User $actor, int $accountId, array $predecessors, int $countId,
        string $evidence, DateTimeImmutable $now): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $evidence = trim($evidence);
        $fail = 'completed_refund_group_revision_selection';
        if ($accountId <= 0 || $countId <= 0 || count($predecessors) < 2 || count($predecessors) > 50
            || mb_strlen($evidence) < 20 || mb_strlen($evidence) > 2000) throw new DomainException($fail);
        foreach ($predecessors as $uuid => $id) {
            if (! is_string($uuid) || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid)
                || ! is_int($id) || $id <= 0) throw new DomainException($fail);
        }
        if (count(array_unique(array_values($predecessors))) !== count($predecessors)) throw new DomainException($fail);
        ksort($predecessors);
        $date = $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now,
            function () use ($actor, $accountId, $predecessors, $countId, $evidence, $date, $fail): array {
                DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
                if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new DomainException('gated_refund_issuance_lock');
                }
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                self::authenticated($actor);
                app(TreasuryRegisterService::class)->authorize($actor);
                $this->guard();
                $unresolved = $this->unresolvedForAccount($accountId);
                if ($unresolved['count'] !== count($predecessors)
                    || app(HeldCashReservation::class)->unpostedCashRefundMinor() !== 0) throw new DomainException($fail);
                $total = $posting = 0;
                $priorCount = null;
                $replay = null;
                $members = [];
                foreach ($predecessors as $uuid => $id) {
                    $request = $this->find($uuid, true);
                    if ($request === null || ($request->after['account_id'] ?? null) !== $accountId
                        || ($request->after['date'] ?? null) !== $date) throw new DomainException($fail);
                    $closed = $this->decision($request);
                    if ($closed === null || $this->verifiedResolution($request, $closed) !== null
                        || DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first()
                        || PaymentRefund::query()->where('disbursement_reference', $request->after['reference'])->lockForUpdate()->first()) {
                        throw new DomainException($fail);
                    }
                    $chain = $this->proposalChain($request, $closed);
                    if ($chain === []) throw new DomainException($fail);
                    $current = end($chain);
                    $retry = (int) $current->id !== $id;
                    $previous = $retry && count($chain) > 1 ? $chain[count($chain) - 2] : $current;
                    if ((int) $previous->id !== $id || ($retry && (($current->after['count_id'] ?? null) !== $countId
                        || ($current->after['supersedes_id'] ?? null) !== $id
                        || (int) $current->actor_user_id !== (int) $actor->id
                        || ($current->after['classification'] ?? null) !== ($previous->after['classification'] ?? null)))
                        || ($replay !== null && $replay !== $retry)) throw new DomainException($fail);
                    $replay = $retry;
                    $q = $previous->after;
                    $classification = $q['classification'] ?? null;
                    if (! in_array($classification, ['posting_required', 'cash_returned', 'no_disbursement'], true)
                        || ! is_int($q['count_id'] ?? null) || $q['count_id'] >= $countId
                        || ($priorCount !== null && $priorCount !== $q['count_id'])) throw new DomainException($fail);
                    $priorCount = $q['count_id'];
                    $minor = FinancialAmount::parse($request->after['amount'])->minor;
                    $total = \App\Support\SignedMinor::add($total, $minor);
                    if ($classification === 'posting_required') $posting = \App\Support\SignedMinor::add($posting, $minor);
                    $members[$uuid] = [$request, $closed, $classification, $previous];
                }
                if ($total !== $unresolved['claimed_minor']) throw new DomainException($fail);
                $this->verifyGroupRevisionMembership($accountId, $priorCount, $predecessors, ! $replay);
                // Validate the new count and make sure it cannot be used twice.
                $this->assertCountUnusedByBatch($countId);
                foreach (AuditEvent::query()->where('event', self::RESOLUTION_APPROVED)->lockForUpdate()->get() as $approval) {
                    if (($approval->after['count_id'] ?? null) === $countId) throw new DomainException($fail);
                }
                app(TreasuryCashCountService::class)->assertFreshObservationVariance($accountId, $countId, $date, -$posting);
                $newCount = DB::table('treasury_cash_counts')->where('id', $countId)->lockForUpdate()->first();
                $newReview = DB::table('treasury_cash_count_reviews')->where('treasury_cash_count_id', $countId)->lockForUpdate()->first();
                if (! $newCount || ! $newReview || in_array((int) $actor->id,
                    [(int) $newCount->counted_by, (int) $newReview->reviewed_by], true)) {
                    throw new DomainException('completed_refund_resolution_independence');
                }
                if (! $replay) {
                    foreach ($members as [$request, $closed, $classification, $previous]) {
                        // Validate prior evidence without requiring that the old count is still fresh.
                        $this->resolutionSources($request, $closed, (int) $actor->id,
                            $classification === 'posting_required', false, -$posting, true);
                    }
                }
                $binding = ['version' => 1, 'account_id' => $accountId, 'count_id' => $countId,
                    'actor_id' => (int) $actor->id, 'date' => $date, 'evidence' => $evidence,
                    'total_claimed_minor' => $total, 'posting_required_minor' => $posting, 'predecessors' => []];
                foreach ($members as $uuid => [$request, $closed, $classification, $previous]) {
                    $binding['predecessors'][$uuid] = ['id' => (int) $previous->id,
                        'hash' => $this->proposalHash($previous->after), 'classification' => $classification];
                }
                $groupHash = $this->proposalHash($binding);
                $ids = [];
                foreach ($members as $uuid => [$request, $closed, $classification, $previous]) {
                    $result = $this->proposeResolutionLocked($actor, $uuid, $countId, $classification,
                        $evidence, $date, $predecessors[$uuid], -$posting, $groupHash);
                    if ($result['created'] === $replay) throw new DomainException($fail);
                    $ids[$uuid] = $result['proposal_id'];
                }
                $this->verifyGroupRevisionMembership($accountId, $countId, $ids, true);
                return ['created' => ! $replay, 'proposal_ids' => $ids, 'count_id' => $countId,
                    'total_claimed_minor' => $total, 'posting_required_minor' => $posting, 'group_revision_hash' => $groupHash,
                    'incident_unresolved' => true, 'financial_posting_created' => false,
                    'spending_authorized' => false, 'closure_authorized' => false];
            });
    }

    /** Narrow no-posting resolution. A counted balance is not a general certification. */
    public function approveResolution(User $actor, string $uuid, string $confirmation, DateTimeImmutable $now, ?int $expectedProposalId = null): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $confirmation = trim($confirmation);
        if (mb_strlen($confirmation) < 20 || mb_strlen($confirmation) > 2000) {
            throw new DomainException('completed_refund_resolution_input');
        }
        $date = $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now,
            function () use ($actor, $uuid, $confirmation, $date, $expectedProposalId): array {
                DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
                if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new DomainException('gated_refund_issuance_lock');
                }
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                self::authenticated($actor);
                app(TreasuryRegisterService::class)->authorize($actor);
                $this->guard();
                $request = $this->find($uuid, true);
                abort_unless($request !== null, 404);
                $closed = $this->decision($request);
                if ($closed === null) throw new DomainException('completed_refund_resolution_closed_request_required');
                $sources = $this->resolutionSources($request, $closed, (int) $actor->id);
                $this->assertExpectedProposal($sources['proposal'], $expectedProposalId);
                $old = $this->verifiedResolution($request, $closed);
                if ($old !== null) {
                    if ($old->event !== self::RESOLUTION_APPROVED || (int) $old->actor_user_id !== (int) $actor->id || $old->after['confirmation'] !== $confirmation) {
                        throw new DomainException('completed_refund_resolution_conflict');
                    }
                    return ['created' => false, 'incident_unresolved' => false, 'resolution' => $old->after];
                }
                $p = $sources['proposal']->after;
                $accountId = $p['account_id'];
                if ($this->unresolvedForAccount($accountId)['count'] !== 1
                    || app(HeldCashReservation::class)->unpostedCashRefundMinor() !== 0) {
                    throw new DomainException('completed_refund_resolution_competing_incidents');
                }
                foreach (AuditEvent::query()->where('event', self::RESOLUTION_APPROVED)->lockForUpdate()->get() as $approval) {
                    if (($approval->after['count_id'] ?? null) === $p['count_id']) {
                        throw new DomainException('completed_refund_resolution_count_reused');
                    }
                }
                $this->assertCountUnusedByBatch($p['count_id']);
                app(TreasuryCashCountService::class)->assertFreshEqualObservation($accountId, $p['count_id'], $date);
                if ($p['proposed_on'] !== $date
                    || app(HeldCashRecordedLiquidityEvidence::class)->balance($accountId, $date) !== $p['counted_minor']) {
                    throw new DomainException('completed_refund_resolution_stale_count');
                }
                self::requireSignedNewSettlement();
                $proof = $sources['identity'] + ['approved_on' => $date, 'confirmation' => $confirmation];
                app(AuditLogger::class)->recordRequired(self::RESOLUTION_APPROVED, [
                    'entity_type' => 'completed_cash_refund_request', 'entity_id' => $uuid,
                    'actor_user_id' => $actor->id, 'source' => 'internal', 'after' => $proof]);
                if ($this->verifiedResolution($request, $closed) === null) {
                    throw new DomainException('completed_refund_resolution_conflict');
                }
                return ['created' => true, 'incident_unresolved' => false, 'resolution' => $proof];
            });
    }

    /** Internal planning only: the actual writer must revalidate under its own gate.
     * No approval record, posting, spending permission or release is created. */
    public function postingResolutionReadiness(User $actor, string $uuid, DateTimeImmutable $now): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $date = $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now,
            function () use ($actor, $uuid, $date): array {
                DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
                if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new DomainException('gated_refund_issuance_lock');
                }
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                self::authenticated($actor);
                app(TreasuryRegisterService::class)->authorize($actor);
                $this->guard();
                $plan = $this->postingResolutionPlanLocked($actor, $uuid, $date);
                $p = $plan['sources']['proposal']->after;
                $minor = $plan['minor'];
                $balance = $plan['balance'];
                return [
                    'operation_uuid' => $uuid, 'account_id' => $p['account_id'],
                    'payment_id' => $p['payment_id'], 'count_id' => $p['count_id'],
                    'claimed_minor' => $minor, 'recorded_balance_minor' => $balance,
                    'observed_balance_minor' => $p['counted_minor'],
                    'expected_subscriber_delta_minor' => -$minor, 'expected_treasury_delta_minor' => -$minor,
                    'evidence_ready_for_writer_review' => true, 'writer_eligibility_verified' => false,
                    'financial_posting_created' => false, 'spending_authorized' => false,
                    'incident_unresolved' => true, 'closure_authorized' => false,
                ];
            });
    }

    /**
     * Internal non-operative evidence review for a complete same-account batch.
     * Every claim must have its own current proposal and share one reviewed
     * observation made after all closed decisions. The shortage is allocated
     * once across the batch; it is never an individual posting capability.
     */
    public function batchPostingResolutionReadiness(User $actor, int $accountId, array $proposalIds, DateTimeImmutable $now): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        if ($accountId <= 0 || count($proposalIds) < 2 || count($proposalIds) > 50) {
            throw new DomainException('completed_refund_batch_selection');
        }
        foreach ($proposalIds as $uuid => $id) {
            if (! is_string($uuid) || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid)
                || ! is_int($id) || $id <= 0) {
                throw new DomainException('completed_refund_batch_selection');
            }
        }
        if (count(array_unique(array_values($proposalIds))) !== count($proposalIds)) {
            throw new DomainException('completed_refund_batch_selection');
        }
        ksort($proposalIds);
        $date = $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now,
            function () use ($actor, $accountId, $proposalIds, $date): array {
                DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
                if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new DomainException('gated_refund_issuance_lock');
                }
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                self::authenticated($actor);
                app(TreasuryRegisterService::class)->authorize($actor);
                $this->guard();
                return $this->batchPostingPlanLocked($actor, $accountId, $proposalIds, $date);
            });
    }

    /** Read-only plan, repeated within the actual batch writer's gate. */
    public function batchPostingPlanLocked(User $actor, int $accountId, array $proposalIds, string $date): array
    {
        $context = FinancialWriteGate::currentContext();
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        app(TreasuryRegisterService::class)->authorize($actor);
        $this->guard();
        if ($context['scope'] !== 'water' || $context['effective_on'] !== $date
            || $accountId <= 0 || count($proposalIds) < 2 || count($proposalIds) > 50) {
            throw new DomainException('completed_refund_batch_selection');
        }
        foreach ($proposalIds as $uuid => $id) {
            if (! is_string($uuid) || ! preg_match('/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/', $uuid)
                || ! is_int($id) || $id <= 0) throw new DomainException('completed_refund_batch_selection');
        }
        if (count(array_unique(array_values($proposalIds))) !== count($proposalIds)) {
            throw new DomainException('completed_refund_batch_selection');
        }
        ksort($proposalIds);
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new DomainException('gated_refund_issuance_lock');
        }
        $unresolved = $this->unresolvedForAccount($accountId);
        if ($unresolved['count'] !== count($proposalIds) || $unresolved['claimed_minor'] <= 0
            || app(HeldCashReservation::class)->unpostedCashRefundMinor() !== 0) {
            throw new DomainException('completed_refund_batch_incomplete');
        }
        $total = $unresolved['claimed_minor'];
        $allocated = 0;
        $shared = null;
        $items = [];
        $references = [];
        foreach ($proposalIds as $uuid => $id) {
            $request = $this->find($uuid, true);
            if ($request === null || ($request->after['account_id'] ?? null) !== $accountId) {
                throw new DomainException('completed_refund_batch_selection');
            }
            $closed = $this->decision($request);
            if ($closed === null || $this->verifiedResolution($request, $closed) !== null) {
                throw new DomainException('completed_refund_batch_selection');
            }
            $sources = $this->resolutionSources($request, $closed, (int) $actor->id, true, false, -$total);
            $this->assertExpectedProposal($sources['proposal'], $id);
            $p = $sources['proposal']->after;
            $intent = $request->after;
            if ($intent['date'] !== $date || $p['proposed_on'] !== $date) {
                throw new DomainException('completed_refund_resolution_stale_count');
            }
            // Source verification checks that each count is later than
            // its own decision. All proposals must bind the same count.
            $observation = [
                'count_id' => $p['count_id'], 'review_id' => $p['review_id'],
                'count_audit_id' => $p['count_audit_id'], 'review_audit_id' => $p['review_audit_id'],
                'recorded_minor' => $p['recorded_minor'], 'counted_minor' => $p['counted_minor'],
                'ledger_hash' => $p['ledger_hash'],
            ];
            if ($shared !== null && $shared !== $observation) {
                throw new DomainException('completed_refund_batch_observation_conflict');
            }
            $shared = $observation;
            if (isset($references[$intent['reference']])) {
                throw new DomainException('completed_refund_batch_selection');
            }
            $references[$intent['reference']] = true;
            $minor = FinancialAmount::parse($intent['amount'])->minor;
            $allocated = \App\Support\SignedMinor::add($allocated, $minor);
            $items[] = ['operation_uuid' => $uuid, 'proposal_id' => $id,
                'payment_id' => $intent['payment_id'], 'reference' => $intent['reference'],
                'claimed_minor' => $minor, 'expected_subscriber_delta_minor' => -$minor,
                'expected_treasury_delta_minor' => -$minor,
                'proposal_hash' => $sources['identity']['proposal_hash']];
        }
        if ($shared === null || $allocated !== $total) {
            throw new DomainException('completed_refund_batch_incomplete');
        }
        $this->verifyGroupRevisionMembership($accountId, $shared['count_id'], $proposalIds, true);
        foreach (AuditEvent::query()->where('event', self::RESOLUTION_APPROVED)->lockForUpdate()->get() as $approval) {
            if (($approval->after['count_id'] ?? null) === $shared['count_id']) {
                throw new DomainException('completed_refund_resolution_count_reused');
            }
        }
        $this->assertCountUnusedByBatch($shared['count_id']);
        app(TreasuryCashCountService::class)->assertFreshObservationVariance(
            $accountId, $shared['count_id'], $date, -$total);
        $balance = app(HeldCashRecordedLiquidityEvidence::class)->balance($accountId, $date);
        if ($balance !== $shared['recorded_minor'] || $balance < $total
            || \App\Support\SignedMinor::subtract($balance, $total) !== $shared['counted_minor']) {
            throw new DomainException('completed_refund_resolution_stale_count');
        }
        return [
            'account_id' => $accountId, 'count_id' => $shared['count_id'],
            'incident_count' => count($items), 'total_claimed_minor' => $total,
            'recorded_balance_minor' => $balance, 'observed_balance_minor' => $shared['counted_minor'],
            'expected_treasury_delta_minor' => -$total, 'items' => $items,
            'evidence_ready_for_batch_review' => true, 'writer_eligibility_verified' => false,
            'financial_posting_created' => false, 'spending_authorized' => false,
            'incident_unresolved' => true, 'closure_authorized' => false,
        ];
    }

    public function mixedResolutionReadiness(User $actor, int $accountId, array $proposalIds, DateTimeImmutable $now): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        if ($accountId <= 0 || count($proposalIds) < 2 || count($proposalIds) > 50) {
            throw new DomainException('completed_refund_batch_selection');
        }
        foreach ($proposalIds as $uuid => $id) {
            if (! is_string($uuid) || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid)
                || ! is_int($id) || $id <= 0) {
                throw new DomainException('completed_refund_batch_selection');
            }
        }
        if (count(array_unique(array_values($proposalIds))) !== count($proposalIds)) {
            throw new DomainException('completed_refund_batch_selection');
        }
        ksort($proposalIds);
        $date = $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now,
            function () use ($actor, $accountId, $proposalIds, $date): array {
                DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
                if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new DomainException('gated_refund_issuance_lock');
                }
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                self::authenticated($actor);
                app(TreasuryRegisterService::class)->authorize($actor);
                $this->guard();
                return $this->mixedResolutionPlanLocked($actor, $accountId, $proposalIds, $date);
            });
    }

    /** Validate all source statements within the same scope-first transaction as the arithmetic.
     * This is not an executable settlement or a transferable authorization token.
     */
    public function mixedSourceReadiness(User $actor, int $accountId, array $proposalIds, array $attestationIds,
        DateTimeImmutable $now): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $date = $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now,
            function () use ($actor, $accountId, $proposalIds, $attestationIds, $date): array {
                DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
                if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new DomainException('gated_refund_issuance_lock');
                }
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                return $this->mixedSourcePlanLocked($actor, $accountId, $proposalIds, $attestationIds, $date);
            });
    }

    public function mixedSourcePlanLocked(User $actor, int $accountId, array $proposalIds, array $attestationIds, string $date): array
    {
        $plan = $this->mixedResolutionPlanLocked($actor, $accountId, $proposalIds, $date, true);
        ksort($proposalIds);
        ksort($attestationIds);
        if (array_keys($proposalIds) !== array_keys($attestationIds)) {
            throw new DomainException('completed_refund_mixed_source_selection');
        }
        foreach ($attestationIds as $id) {
            if (! is_int($id) || $id <= 0) throw new DomainException('completed_refund_mixed_source_selection');
        }
        if (count(array_unique(array_values($attestationIds))) !== count($attestationIds)) {
            throw new DomainException('completed_refund_mixed_source_selection');
        }
        $evidence = [];
        foreach ($plan['items'] as $item) {
            $uuid = $item['operation_uuid'];
            $id = $attestationIds[$uuid];
            $proof = $this->withDocumentContextLocked($actor, $uuid, $item['proposal_id'], $date,
                function (User $actor, AuditEvent $request, AuditEvent $closed, AuditEvent $proposal, array $excluded, string $date)
                    use ($id): array {
                    return $this->revalidateSourceProofLocked($actor, $request, $closed, $proposal, $excluded, $date, $id);
                });
            $evidence[] = ['operation_uuid' => $uuid, 'proposal_id' => $item['proposal_id'],
                'proposal_hash' => $item['proposal_hash'], 'classification' => $item['classification'],
                'claimed_minor' => $item['claimed_minor'], 'attestation_id' => $id,
                'attestation_hash' => $proof['attestation_hash'], 'public_key_sha256' => $proof['public_key_sha256'],
                'verified_public_key' => $proof['verified_public_key']];
        }
        // Bind the complete ordered selection, date, reviewer, and cash amounts.
        // Digest is for comparison/audit only, not a reusable write capability.
        $binding = ['version' => 1, 'reviewer_id' => (int) $actor->id, 'effective_on' => $date,
            'account_id' => $accountId, 'count_id' => $plan['count_id'],
            'recorded_balance_minor' => $plan['recorded_balance_minor'],
            'observed_balance_minor' => $plan['observed_balance_minor'],
            'total_claimed_minor' => $plan['total_claimed_minor'],
            'posting_required_minor' => $plan['posting_required_minor'], 'sources' => $evidence];
        return $plan + ['all_source_signatures_revalidated' => true, 'source_binding' => $binding,
            'source_binding_sha256' => hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR)),
            'physical_facts_automatically_verified' => false];
    }

    /** Read-only mixed arithmetic review; not an operative writer. */
    private function mixedResolutionPlanLocked(User $actor, int $accountId, array $proposalIds, string $date, bool $signed = false): array
    {
        $context = FinancialWriteGate::currentContext();
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        app(TreasuryRegisterService::class)->authorize($actor);
        $this->guard();
        if ($context['scope'] !== 'water' || $context['effective_on'] !== $date
            || $accountId <= 0 || count($proposalIds) < ($signed ? 1 : 2) || count($proposalIds) > 50) {
            throw new DomainException('completed_refund_batch_selection');
        }
        foreach ($proposalIds as $uuid => $id) {
            if (! is_string($uuid) || ! preg_match('/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/', $uuid)
                || ! is_int($id) || $id <= 0) throw new DomainException('completed_refund_batch_selection');
        }
        if (count(array_unique(array_values($proposalIds))) !== count($proposalIds)) {
            throw new DomainException('completed_refund_batch_selection');
        }
        ksort($proposalIds);
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new DomainException('gated_refund_issuance_lock');
        }
        $unresolved = $this->unresolvedForAccount($accountId);
        if ($unresolved['count'] !== count($proposalIds) || $unresolved['claimed_minor'] <= 0
            || app(HeldCashReservation::class)->unpostedCashRefundMinor() !== 0) {
            throw new DomainException('completed_refund_batch_incomplete');
        }
        $total = $unresolved['claimed_minor'];
        $allocated = 0;
        $postingTotal = 0;
        $classificationByUuid = [];
        foreach ($proposalIds as $uuid => $id) {
            $request = $this->find($uuid, true);
            if ($request === null || ($request->after['account_id'] ?? null) !== $accountId) {
                throw new DomainException('completed_refund_batch_selection');
            }
            $closed = $this->decision($request);
            if ($closed === null || $this->verifiedResolution($request, $closed) !== null) {
                throw new DomainException('completed_refund_batch_selection');
            }
            $chain = $this->proposalChain($request, $closed);
            if ($chain === []) throw new DomainException('completed_refund_resolution_evidence');
            $proposal = end($chain);
            $this->assertExpectedProposal($proposal, $id);
            $classification = $proposal->after['classification'] ?? null;
            if (! in_array($classification, ['posting_required', 'no_disbursement', 'cash_returned'], true)) {
                throw new DomainException('completed_refund_resolution_evidence');
            }
            $classificationByUuid[$uuid] = $classification;
            if ($classification === 'posting_required') {
                $postingTotal = \App\Support\SignedMinor::add($postingTotal, FinancialAmount::parse($request->after['amount'])->minor);
            }
        }
        if ($postingTotal > $total || (! $signed && $postingTotal === $total)) {
            throw new DomainException('completed_refund_mixed_classification_required');
        }
        $shared = null;
        $items = [];
        $references = [];
        foreach ($proposalIds as $uuid => $id) {
            $request = $this->find($uuid, true);
            if ($request === null || ($request->after['account_id'] ?? null) !== $accountId) {
                throw new DomainException('completed_refund_batch_selection');
            }
            $closed = $this->decision($request);
            if ($closed === null || $this->verifiedResolution($request, $closed) !== null) {
                throw new DomainException('completed_refund_batch_selection');
            }
            $posting = $classificationByUuid[$uuid] === 'posting_required';
            $sources = $this->resolutionSources($request, $closed, (int) $actor->id, $posting, false, -$postingTotal, true);
            $this->assertExpectedProposal($sources['proposal'], $id);
            $p = $sources['proposal']->after;
            $intent = $request->after;
            if ($intent['date'] !== $date || $p['proposed_on'] !== $date) {
                throw new DomainException('completed_refund_resolution_stale_count');
            }
            // Source verification checks that each count is later than
            // its own decision. All proposals must bind the same count.
            $observation = [
                'count_id' => $p['count_id'], 'review_id' => $p['review_id'],
                'count_audit_id' => $p['count_audit_id'], 'review_audit_id' => $p['review_audit_id'],
                'recorded_minor' => $p['recorded_minor'], 'counted_minor' => $p['counted_minor'],
                'ledger_hash' => $p['ledger_hash'],
            ];
            if ($shared !== null && $shared !== $observation) {
                throw new DomainException('completed_refund_batch_observation_conflict');
            }
            $shared = $observation;
            if (isset($references[$intent['reference']])) {
                throw new DomainException('completed_refund_batch_selection');
            }
            $references[$intent['reference']] = true;
            $minor = FinancialAmount::parse($intent['amount'])->minor;
            $allocated = \App\Support\SignedMinor::add($allocated, $minor);
            $items[] = ['operation_uuid' => $uuid, 'proposal_id' => $id,
                'payment_id' => $intent['payment_id'], 'reference' => $intent['reference'],
                'claimed_minor' => $minor, 'classification' => $classificationByUuid[$uuid],
                'expected_subscriber_delta_minor' => $posting ? -$minor : 0,
                'expected_treasury_delta_minor' => $posting ? -$minor : 0,
                'proposal_hash' => $sources['identity']['proposal_hash']];
        }
        if ($shared === null || $allocated !== $total) {
            throw new DomainException('completed_refund_batch_incomplete');
        }
        $this->verifyGroupRevisionMembership($accountId, $shared['count_id'], $proposalIds, true);
        foreach (AuditEvent::query()->where('event', self::RESOLUTION_APPROVED)->lockForUpdate()->get() as $approval) {
            if (($approval->after['count_id'] ?? null) === $shared['count_id']) {
                throw new DomainException('completed_refund_resolution_count_reused');
            }
        }
        $this->assertCountUnusedByBatch($shared['count_id']);
        app(TreasuryCashCountService::class)->assertFreshObservationVariance(
            $accountId, $shared['count_id'], $date, -$postingTotal);
        $balance = app(HeldCashRecordedLiquidityEvidence::class)->balance($accountId, $date);
        if ($balance !== $shared['recorded_minor'] || $balance < $postingTotal
            || \App\Support\SignedMinor::subtract($balance, $postingTotal) !== $shared['counted_minor']) {
            throw new DomainException('completed_refund_resolution_stale_count');
        }
        return [
            'account_id' => $accountId, 'count_id' => $shared['count_id'],
            'incident_count' => count($items), 'total_claimed_minor' => $total,
            'recorded_balance_minor' => $balance, 'observed_balance_minor' => $shared['counted_minor'],
            'posting_required_minor' => $postingTotal, 'nonposting_claimed_minor' => \App\Support\SignedMinor::subtract($total, $postingTotal),
            'expected_treasury_delta_minor' => -$postingTotal, 'items' => $items,
            'aggregate_arithmetic_verified' => true, 'individual_nonposting_evidence_verified' => false,
            'evidence_ready_for_batch_review' => false, 'writer_eligibility_verified' => false,
            'financial_posting_created' => false, 'spending_authorized' => false,
            'incident_unresolved' => true, 'closure_authorized' => false,
        ];
    }

    /** Internal candidate only. No instruction to send money. */
    public function postBatchResolution(User $reviewer, int $accountId, array $proposalIds,
        string $confirmation, DateTimeImmutable $now): array
    {
        self::authenticated($reviewer);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        return app(GatedRefundWriter::class)->recordBatchResolvedCashRefund(
            $reviewer, $accountId, $proposalIds, $confirmation, $now);
    }

    /** Internal staging/test candidate; the writer repeats all checks under its gate. */
    public function postMixedResolution(User $reviewer, int $accountId, array $proposalIds, array $attestationIds,
        string $confirmation, DateTimeImmutable $now): array
    {
        self::authenticated($reviewer);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        return app(GatedRefundWriter::class)->recordBatchResolvedCashRefund(
            $reviewer, $accountId, $proposalIds, $confirmation, $now, $attestationIds);
    }

    private function batchAuditForCount(int $countId): ?AuditEvent
    {
        $rows = AuditEvent::query()->where('event', self::BATCH_APPROVED)->where('entity_id', (string) $countId)
            ->lockForUpdate()->get();
        if ($rows->count() > 1) throw new DomainException('completed_refund_batch_conflict');
        return $rows->first();
    }

    private function assertCountUnusedByBatch(int $countId): void
    {
        if ($this->batchAuditForCount($countId) !== null) {
            throw new DomainException('completed_refund_resolution_count_reused');
        }
    }

    public function batchReplayLocked(User $reviewer, int $accountId, array $proposalIds, string $confirmation, ?array $attestationIds = null): ?array
    {
        FinancialWriteGate::currentContext();
        self::authenticated($reviewer);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $this->guard();
        if ($accountId <= 0 || count($proposalIds) < ($attestationIds === null ? 2 : 1) || count($proposalIds) > 50) {
            throw new DomainException('completed_refund_batch_selection');
        }
        ksort($proposalIds);
        $first = $this->find(array_key_first($proposalIds), true);
        if ($first === null) throw new DomainException('completed_refund_batch_selection');
        $closed = $this->decision($first);
        if ($closed === null) return null;
        $group = $this->verifiedBatchResolution($first, $closed);
        if ($group === null) return null;
        $p = $group->after;
        $mixed = in_array($p['format'] ?? null, ['mixed-v1', 'signed-v2'], true);
        if ($mixed !== ($attestationIds !== null)) throw new DomainException('completed_refund_batch_conflict');
        if ($mixed) {
            ksort($attestationIds);
            $binding = json_decode($p['source_binding_json'], true, 512, JSON_THROW_ON_ERROR);
            $expected = array_column($binding['sources'], 'attestation_id', 'operation_uuid');
            if ($expected !== $attestationIds) throw new DomainException('completed_refund_batch_conflict');
        }
        if ($p['account_id'] !== $accountId || (int) $group->actor_user_id !== (int) $reviewer->id
            || $p['confirmation'] !== $confirmation || $p['proposal_ids_json'] !== json_encode($proposalIds, JSON_THROW_ON_ERROR)) {
            throw new DomainException('completed_refund_batch_conflict');
        }
        return ['registry_created' => false, 'batch' => $p,
            'current_active_release_liquidity' => app(ActiveHeldCashRelease::class)->currentLiquidity($accountId)];
    }

    /** Group completion is callable only while the writer owns the exact plan. */
    public function completeBatchPostingLocked(GatedRefundWriter $writer, User $reviewer, array $plan, string $confirmation): array
    {
        $context = FinancialWriteGate::currentContext();
        self::authenticated($reviewer);
        $this->guard();
        if (! $writer->allowsBatchCompletion($plan, (int) $reviewer->id, $confirmation)
            || $context['scope'] !== 'water') {
            throw new DomainException('completed_refund_batch_writer_required');
        }
        if (isset($plan['source_binding'])) return $this->completeMixedPostingLocked($reviewer, $plan, $confirmation);
        $accountId = $plan['account_id'];
        $countId = $plan['count_id'];
        $total = $plan['total_claimed_minor'];
        $this->assertCountUnusedByBatch($countId);
        $ids = $hashes = [];
        foreach ($plan['items'] as $item) {
            $request = $this->find($item['operation_uuid'], true);
            if ($request === null) throw new DomainException('completed_refund_batch_conflict');
            $closed = $this->decision($request);
            if ($closed === null || $this->verifiedResolution($request, $closed) !== null) {
                throw new DomainException('completed_refund_batch_conflict');
            }
            $sources = $this->resolutionSources($request, $closed, (int) $reviewer->id, true, true, -$total);
            $this->assertExpectedProposal($sources['proposal'], $item['proposal_id']);
            if ($sources['identity']['proposal_hash'] !== $item['proposal_hash']
                || $sources['identity']['count_id'] !== $countId || $sources['identity']['account_id'] !== $accountId) {
                throw new DomainException('completed_refund_batch_conflict');
            }
            $this->verifyResolutionPosting($request->after, (int) $reviewer->id, $confirmation);
            $ids[$request->entity_id] = $item['proposal_id'];
            $hashes[$request->entity_id] = $this->proposalHash($sources['identity']);
        }
        if (app(HeldCashRecordedLiquidityEvidence::class)->balance($accountId, $context['effective_on'])
            !== $plan['observed_balance_minor']) {
            throw new DomainException('completed_refund_resolution_posting_balance');
        }
        ksort($ids);
        ksort($hashes);
        $proof = ['account_id' => $accountId, 'count_id' => $countId,
            'incident_count' => count($ids), 'total_claimed_minor' => $total,
            'recorded_balance_minor' => $plan['recorded_balance_minor'], 'observed_balance_minor' => $plan['observed_balance_minor'],
            'proposal_ids_json' => json_encode($ids, JSON_THROW_ON_ERROR),
            'source_hashes_json' => json_encode($hashes, JSON_THROW_ON_ERROR),
            'approved_on' => $context['effective_on'], 'confirmation' => $confirmation,
            'financial_posting_created' => true];
        $group = app(AuditLogger::class)->recordRequired(self::BATCH_APPROVED, [
            'entity_type' => 'completed_cash_refund_request', 'entity_id' => (string) $countId,
            'actor_user_id' => $reviewer->id, 'source' => 'internal', 'after' => $proof]);
        foreach (array_keys($ids) as $uuid) {
            $request = $this->find($uuid, true);
            if ($this->verifiedResolution($request, $this->decision($request))?->id !== $group->id) {
                throw new DomainException('completed_refund_batch_conflict');
            }
        }
        return $proof;
    }

    private function verifiedBatchResolution(AuditEvent $request, AuditEvent $closed): ?AuditEvent
    {
        $chain = $this->proposalChain($request, $closed);
        if ($chain === []) return null;
        $current = end($chain);
        $countId = $current->after['count_id'] ?? null;
        if (! is_int($countId) || $countId <= 0) throw new DomainException('completed_refund_batch_conflict');
        $group = $this->batchAuditForCount($countId);
        if ($group === null) return null;
        $p = $group->after;
        if (in_array($p['format'] ?? null, ['mixed-v1', 'signed-v2'], true)) return $this->verifiedMixedBatch($request, $current, $group, $countId);
        $fail = 'completed_refund_batch_conflict';
        if (! is_array($p) || count($p) !== 11 || $group->entity_type !== 'completed_cash_refund_request'
            || $group->source !== 'internal' || (int) $group->actor_user_id <= 0
            || ($p['count_id'] ?? null) !== $countId || ($p['financial_posting_created'] ?? null) !== true
            || ! is_int($p['account_id'] ?? null) || ! is_int($p['total_claimed_minor'] ?? null) || $p['total_claimed_minor'] <= 0
            || ! is_int($p['recorded_balance_minor'] ?? null) || ! is_int($p['observed_balance_minor'] ?? null)
            || ! is_string($p['approved_on'] ?? null) || ! is_string($p['confirmation'] ?? null)
            || mb_strlen(trim($p['confirmation'])) < 20 || mb_strlen($p['confirmation']) > 2000
            || ! is_string($p['proposal_ids_json'] ?? null) || ! is_string($p['source_hashes_json'] ?? null)) {
            throw new DomainException($fail);
        }
        $ids = json_decode($p['proposal_ids_json'], true, 512, JSON_THROW_ON_ERROR);
        $hashes = json_decode($p['source_hashes_json'], true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($ids) || ! is_array($hashes) || count($ids) < 2 || count($ids) > 50
            || count($ids) !== ($p['incident_count'] ?? null) || array_keys($ids) !== array_keys($hashes)
            || ($ids[$request->entity_id] ?? null) !== (int) $current->id
            || count(array_unique(array_values($ids))) !== count($ids)) {
            throw new DomainException($fail);
        }
        $this->verifyGroupRevisionMembership($p['account_id'], $countId, $ids, true);
        $total = 0;
        foreach ($ids as $uuid => $proposalId) {
            $member = $this->find($uuid, true);
            if ($member === null) throw new DomainException($fail);
            $decision = $this->decision($member);
            if ($decision === null || AuditEvent::query()->where('event', self::RESOLUTION_APPROVED)
                ->where('entity_id', $uuid)->lockForUpdate()->first()) throw new DomainException($fail);
            $sources = $this->resolutionSources($member, $decision, (int) $group->actor_user_id, true, true, -$p['total_claimed_minor']);
            $q = $sources['proposal']->after;
            if ($proposalId !== (int) $sources['proposal']->id || (int) $group->id <= $proposalId
                || $q['count_id'] !== $countId || $q['account_id'] !== $p['account_id']
                || $q['recorded_minor'] !== $p['recorded_balance_minor'] || $q['counted_minor'] !== $p['observed_balance_minor']
                || $q['proposed_on'] !== $p['approved_on'] || $member->after['date'] !== $p['approved_on']
                || $hashes[$uuid] !== $this->proposalHash($sources['identity'])) {
                throw new DomainException($fail);
            }
            $this->verifyResolutionPosting($member->after, (int) $group->actor_user_id, $p['confirmation']);
            $total = \App\Support\SignedMinor::add($total, FinancialAmount::parse($member->after['amount'])->minor);
        }
        if ($total !== $p['total_claimed_minor']
            || \App\Support\SignedMinor::subtract($p['recorded_balance_minor'], $total) !== $p['observed_balance_minor']) {
            throw new DomainException($fail);
        }
        return $group;
    }

    private function completeMixedPostingLocked(User $reviewer, array $plan, string $confirmation): array
    {
        $context = FinancialWriteGate::currentContext();
        $binding = $plan['source_binding'];
        $accountId = $plan['account_id'];
        $countId = $plan['count_id'];
        $this->assertCountUnusedByBatch($countId);
        $ids = [];
        foreach ($plan['items'] as $item) $ids[$item['operation_uuid']] = $item['proposal_id'];
        ksort($ids);
        if (app(HeldCashRecordedLiquidityEvidence::class)->balance($accountId, $context['effective_on'])
            !== $plan['observed_balance_minor']) {
            throw new DomainException('completed_refund_resolution_posting_balance');
        }
        $format = count($ids) === 1 || $plan['posting_required_minor'] === $plan['total_claimed_minor'] ? 'signed-v2' : 'mixed-v1';
        $proof = ['format' => $format, 'account_id' => $accountId, 'count_id' => $countId,
            'incident_count' => count($ids), 'total_claimed_minor' => $plan['total_claimed_minor'],
            'posting_required_minor' => $plan['posting_required_minor'],
            'recorded_balance_minor' => $plan['recorded_balance_minor'], 'observed_balance_minor' => $plan['observed_balance_minor'],
            'proposal_ids_json' => json_encode($ids, JSON_THROW_ON_ERROR),
            'source_binding_json' => json_encode($binding, JSON_THROW_ON_ERROR),
            'source_binding_sha256' => $plan['source_binding_sha256'],
            'approved_on' => $context['effective_on'], 'confirmation' => $confirmation,
            'financial_posting_created' => $plan['posting_required_minor'] > 0];
        $group = app(AuditLogger::class)->recordRequired(self::BATCH_APPROVED, [
            'entity_type' => 'completed_cash_refund_request', 'entity_id' => (string) $countId,
            'actor_user_id' => $reviewer->id, 'source' => 'internal', 'after' => $proof]);
        foreach (array_keys($ids) as $uuid) {
            $request = $this->find($uuid, true);
            if ($this->verifiedResolution($request, $this->decision($request))?->id !== $group->id) {
                throw new DomainException('completed_refund_batch_conflict');
            }
        }
        return $proof;
    }

    /** Reconstruct the entire group from immutable sources; never require current trust
     * to verify a completed posting. Snapshot keys are checked against the signed
     * statement and attestation fingerprint, not used to authorize pending work.
     */
    private function verifiedMixedBatch(AuditEvent $request, AuditEvent $current, AuditEvent $group, int $countId): AuditEvent
    {
        $p = $group->after;
        $fail = 'completed_refund_batch_conflict';
        if (! is_array($p) || count($p) !== 14 || $group->entity_type !== 'completed_cash_refund_request'
            || $group->source !== 'internal' || (int) $group->actor_user_id <= 0
            || ($p['count_id'] ?? null) !== $countId || ! is_int($p['account_id'] ?? null)
            || ! is_int($p['total_claimed_minor'] ?? null) || $p['total_claimed_minor'] <= 0
            || ! is_int($p['posting_required_minor'] ?? null) || $p['posting_required_minor'] < 0
            || $p['posting_required_minor'] > $p['total_claimed_minor']
            || ($p['financial_posting_created'] ?? null) !== ($p['posting_required_minor'] > 0)
            || ! is_int($p['recorded_balance_minor'] ?? null) || ! is_int($p['observed_balance_minor'] ?? null)
            || ! is_string($p['approved_on'] ?? null) || ! is_string($p['confirmation'] ?? null)
            || mb_strlen(trim($p['confirmation'])) < 20 || mb_strlen($p['confirmation']) > 2000
            || ! is_string($p['proposal_ids_json'] ?? null) || ! is_string($p['source_binding_json'] ?? null)
            || ! is_string($p['source_binding_sha256'] ?? null)
            || ! hash_equals($p['source_binding_sha256'], hash('sha256', $p['source_binding_json']))) {
            throw new DomainException($fail);
        }
        $ids = json_decode($p['proposal_ids_json'], true, 512, JSON_THROW_ON_ERROR);
        $binding = json_decode($p['source_binding_json'], true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($ids) || count($ids) < 1 || count($ids) > 50 || count($ids) !== ($p['incident_count'] ?? null)
            || ($ids[$request->entity_id] ?? null) !== (int) $current->id || count(array_unique(array_values($ids))) !== count($ids)
            || ! is_array($binding) || ! is_array($binding['sources'] ?? null) || count($binding['sources']) !== count($ids)) {
            throw new DomainException($fail);
        }
        $format = count($ids) === 1 || $p['posting_required_minor'] === $p['total_claimed_minor'] ? 'signed-v2' : 'mixed-v1';
        if (($p['format'] ?? null) !== $format) throw new DomainException($fail);
        $this->verifyGroupRevisionMembership($p['account_id'], $countId, $ids, true);
        $reviewer = User::query()->lockForUpdate()->findOrFail($group->actor_user_id);
        $total = $postingTotal = 0;
        $evidence = [];
        $used = [];
        foreach ($ids as $uuid => $proposalId) {
            $item = $binding['sources'][count($evidence)] ?? null;
            if (! is_array($item) || ($item['operation_uuid'] ?? null) !== $uuid || ($item['proposal_id'] ?? null) !== $proposalId
                || ! is_int($item['attestation_id'] ?? null) || $item['attestation_id'] <= 0
                || isset($used[$item['attestation_id']]) || ! is_string($item['verified_public_key'] ?? null)
                || (int) $group->id <= $item['attestation_id']) throw new DomainException($fail);
            $used[$item['attestation_id']] = true;
            $member = $this->find($uuid, true);
            if ($member === null) throw new DomainException($fail);
            $decision = $this->decision($member);
            if ($decision === null || AuditEvent::query()->where('event', self::RESOLUTION_APPROVED)
                ->where('entity_id', $uuid)->lockForUpdate()->first()) throw new DomainException($fail);
            $chain = $this->proposalChain($member, $decision);
            if ($chain === []) throw new DomainException($fail);
            $proposal = end($chain);
            $posting = ($proposal->after['classification'] ?? null) === 'posting_required';
            $sources = $this->resolutionSources($member, $decision, (int) $reviewer->id, $posting, $posting,
                -$p['posting_required_minor'], true);
            $q = $sources['proposal']->after;
            if ($proposalId !== (int) $proposal->id || $q['count_id'] !== $countId || $q['account_id'] !== $p['account_id']
                || $q['recorded_minor'] !== $p['recorded_balance_minor'] || $q['counted_minor'] !== $p['observed_balance_minor']
                || $q['proposed_on'] !== $p['approved_on'] || $member->after['date'] !== $p['approved_on']) throw new DomainException($fail);
            $source = $this->documentContext($reviewer, $uuid, $proposalId, $p['approved_on'],
                function (User $actor, AuditEvent $request, AuditEvent $closed, AuditEvent $proposal, array $excluded, string $date)
                    use ($item): array {
                    return $this->revalidateSourceProofLocked($actor, $request, $closed, $proposal, $excluded, $date,
                        $item['attestation_id'], true, $item['verified_public_key']);
                }, true);
            if ($posting) $this->verifyResolutionPosting($member->after, (int) $reviewer->id, $p['confirmation']);
            $minor = FinancialAmount::parse($member->after['amount'])->minor;
            $total = \App\Support\SignedMinor::add($total, $minor);
            if ($posting) $postingTotal = \App\Support\SignedMinor::add($postingTotal, $minor);
            $evidence[] = ['operation_uuid' => $uuid, 'proposal_id' => $proposalId,
                'proposal_hash' => $sources['identity']['proposal_hash'], 'classification' => $q['classification'],
                'claimed_minor' => $minor, 'attestation_id' => $source['attestation_id'],
                'attestation_hash' => $source['attestation_hash'], 'public_key_sha256' => $source['public_key_sha256'],
                'verified_public_key' => $source['verified_public_key']];
        }
        $expected = ['version' => 1, 'reviewer_id' => (int) $reviewer->id, 'effective_on' => $p['approved_on'],
            'account_id' => $p['account_id'], 'count_id' => $countId, 'recorded_balance_minor' => $p['recorded_balance_minor'],
            'observed_balance_minor' => $p['observed_balance_minor'], 'total_claimed_minor' => $total,
            'posting_required_minor' => $postingTotal, 'sources' => $evidence];
        if ($p['source_binding_json'] !== json_encode($expected, JSON_THROW_ON_ERROR)
            || $total !== $p['total_claimed_minor'] || $postingTotal !== $p['posting_required_minor']
            || \App\Support\SignedMinor::subtract($p['recorded_balance_minor'], $postingTotal) !== $p['observed_balance_minor']) {
            throw new DomainException($fail);
        }
        return $group;
    }

    private function postingResolutionPlanLocked(User $actor, string $uuid, string $date): array
    {
        FinancialWriteGate::currentContext();
        $this->guard();
    $request = $this->find($uuid, true);
    abort_unless($request !== null, 404);
    $closed = $this->decision($request);
    if ($closed === null) throw new DomainException('completed_refund_resolution_closed_request_required');
    $sources = $this->resolutionSources($request, $closed, (int) $actor->id, true);
    $p = $sources['proposal']->after;
    $intent = $request->after;
    $minor = FinancialAmount::parse($intent['amount'])->minor;
    if ($this->verifiedResolution($request, $closed) !== null
        || $this->unresolvedForAccount($p['account_id'])['count'] !== 1
        || app(HeldCashReservation::class)->unpostedCashRefundMinor() !== 0) {
        throw new DomainException('completed_refund_resolution_competing_incidents');
    }
    foreach (AuditEvent::query()->where('event', self::RESOLUTION_APPROVED)->lockForUpdate()->get() as $approval) {
        if (($approval->after['count_id'] ?? null) === $p['count_id']) {
            throw new DomainException('completed_refund_resolution_count_reused');
        }
    }
    // Initially accept only same-day claims, never backdate a correction.
    if ($intent['date'] !== $date || $p['proposed_on'] !== $date) {
        throw new DomainException('completed_refund_resolution_stale_count');
    }
    $this->assertCountUnusedByBatch($p['count_id']);
    app(TreasuryCashCountService::class)->assertFreshObservationVariance(
        $p['account_id'], $p['count_id'], $date, -$minor);
    $balance = app(HeldCashRecordedLiquidityEvidence::class)->balance($p['account_id'], $date);
    if ($balance !== $p['recorded_minor'] || $balance < $minor
        || \App\Support\SignedMinor::subtract($balance, $minor) !== $p['counted_minor']) {
        throw new DomainException('completed_refund_resolution_stale_count');
    }
        return compact('sources', 'minor', 'balance');
    }

    public function postResolution(User $reviewer, string $uuid, string $confirmation, DateTimeImmutable $now, ?int $expectedProposalId = null): array
    {
        self::authenticated($reviewer);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $request = $this->find($uuid, false);
        abort_unless($request !== null, 404);
        $p = $request->after;
        if (! is_array($p)) throw new DomainException('completed_cash_refund_request_conflict');
        return app(GatedRefundWriter::class)->recordResolvedCashRefund(User::findOrFail($request->actor_user_id),
            $reviewer, $p, $confirmation, $now, $expectedProposalId);
    }

    /** Called only by the existing refund writer, under the scope and issuance locks. */
    public function verifyPostingResolutionLocked(array $intent, User $reviewer, string $confirmation, bool $replay, ?int $expectedProposalId = null): ?array
    {
        $context = FinancialWriteGate::currentContext();
        $this->guard();
        self::authenticated($reviewer);
        $request = $this->find($intent['operation_uuid'], true);
        if ($request === null) throw new DomainException('completed_cash_refund_request_required');
        $this->assertIntent($request, $intent);
        $closed = $this->decision($request);
        if ($closed === null) throw new DomainException('completed_refund_resolution_closed_request_required');
        $chain = $this->proposalChain($request, $closed);
        if ($chain === []) throw new DomainException('completed_refund_resolution_evidence');
        $this->assertExpectedProposal(end($chain), $expectedProposalId);
        if ($replay) {
            $approval = $this->verifiedResolution($request, $closed);
            if ($approval === null || $approval->event !== self::RESOLUTION_APPROVED || ($approval->after['financial_posting_created'] ?? null) !== true
                || (int) $approval->actor_user_id !== (int) $reviewer->id
                || $approval->after['confirmation'] !== $confirmation) {
                throw new DomainException('completed_refund_resolution_conflict');
            }
            return null;
        }
        $plan = $this->postingResolutionPlanLocked($reviewer, $request->entity_id, $context['effective_on']);
        self::requireSignedNewSettlement();
        return $plan['sources']['identity'] + [
            'approved_on' => $context['effective_on'], 'confirmation' => $confirmation,
            'observed_balance_minor' => $plan['sources']['proposal']->after['counted_minor'],
        ];
    }

    public function completePostingResolutionLocked(array $intent, User $reviewer, array $plan): void
    {
        FinancialWriteGate::currentContext();
        $this->guard();
        self::authenticated($reviewer);
        $request = $this->find($intent['operation_uuid'], true);
        if ($request === null) throw new DomainException('completed_cash_refund_request_required');
        $this->assertIntent($request, $intent);
        $closed = $this->decision($request);
        if ($closed === null || $this->verifiedResolution($request, $closed) !== null) {
            throw new DomainException('completed_refund_resolution_conflict');
        }
        $sources = $this->resolutionSources($request, $closed, (int) $reviewer->id, true, true);
        foreach ($sources['identity'] as $key => $value) {
            if ($key !== 'financial_posting_created' && ($plan[$key] ?? null) !== $value) {
                throw new DomainException('completed_refund_resolution_conflict');
            }
        }
        $this->verifyResolutionPosting($intent, (int) $reviewer->id, $plan['confirmation']);
        if ($plan['observed_balance_minor'] !== $sources['proposal']->after['counted_minor']
            || app(HeldCashRecordedLiquidityEvidence::class)->balance($intent['account_id'], $plan['approved_on'])
                !== $plan['observed_balance_minor']) {
            throw new DomainException('completed_refund_resolution_posting_balance');
        }
        $proof = $sources['identity'] + [
            'approved_on' => $plan['approved_on'], 'confirmation' => $plan['confirmation'],
        ];
        app(AuditLogger::class)->recordRequired(self::RESOLUTION_APPROVED, [
            'entity_type' => 'completed_cash_refund_request', 'entity_id' => $request->entity_id,
            'actor_user_id' => $reviewer->id, 'source' => 'internal', 'after' => $proof]);
        if ($this->verifiedResolution($request, $closed) === null) {
            throw new DomainException('completed_refund_resolution_conflict');
        }
    }

    private function verifyResolutionPosting(array $intent, int $reviewerId, string $confirmation): void
    {
        app(GatedRefundWriter::class)->verifyIncidentPosting($intent);
        $refund = PaymentRefund::query()->where('disbursement_reference', $intent['reference'])->lockForUpdate()->sole();
        $audit = AuditEvent::query()->where('event', 'financial.completed_cash_refund')
            ->where('entity_type', PaymentRefund::class)->where('entity_id', (string) $refund->id)->lockForUpdate()->sole();
        if ((int) $audit->actor_user_id !== $reviewerId || ($audit->after['confirmation'] ?? null) !== $confirmation) {
            throw new DomainException('completed_refund_resolution_conflict');
        }
    }

    /**
     * Internal candidate, not a file upload or disbursement authorization.
     * The caller must retain the original in controlled custody. Only its
     * digest and metadata are persisted; review recomputes the digest from bytes.
     */
    public function recordResolutionDocument(User $actor, string $uuid, int $proposalId,
        string $documentReference, string $bytes, DateTimeImmutable $now): array
    {
        $reference = mb_strtoupper(trim($documentReference));
        $this->validateDocumentInput($reference, $bytes);
        return $this->withDocumentContext($actor, $uuid, $proposalId, $now,
            function (User $actor, AuditEvent $request, AuditEvent $closed, AuditEvent $proposal, array $excluded, string $date)
                use ($reference, $bytes): array {
                if (in_array((int) $actor->id, $excluded, true)) {
                    throw new DomainException('completed_refund_document_independence');
                }
                $digest = hash('sha256', $bytes);
                $proof = $this->documentIdentity($request, $closed, $proposal) + [
                    'document_reference' => $reference, 'sha256' => $digest, 'byte_count' => strlen($bytes),
                    'recorded_on' => $date,
                ];
                // All writes are serialized by the issuance fence. Never
                // reserve one document/reference for two claims or proposals.
                foreach (AuditEvent::query()->where('event', self::DOCUMENT_RECORDED)->orderBy('id')->lockForUpdate()->get() as $row) {
                    $p = $row->after;
                    if (! is_array($p) || ! is_string($p['sha256'] ?? null)
                        || $row->entity_id !== $p['sha256'] || ! is_string($p['document_reference'] ?? null)) {
                        throw new DomainException('completed_refund_document_conflict');
                    }
                    if ($p['sha256'] !== $digest && $p['document_reference'] !== $reference
                        && ($p['proposal_id'] ?? null) !== (int) $proposal->id) continue;
                    if ((int) $row->actor_user_id !== (int) $actor->id || $row->source !== 'internal'
                        || $row->entity_type !== 'completed_cash_refund_request'
                        || $this->proposalHash($p) !== $this->proposalHash($proof)) {
                        throw new DomainException('completed_refund_document_conflict');
                    }
                    return $this->documentResult($row, false, false);
                }
                $row = app(AuditLogger::class)->recordRequired(self::DOCUMENT_RECORDED, [
                    'entity_type' => 'completed_cash_refund_request', 'entity_id' => $digest,
                    'actor_user_id' => $actor->id, 'source' => 'internal', 'after' => $proof]);
                return $this->documentResult($row, true, false);
            });
    }

    public function reviewResolutionDocument(User $actor, string $uuid, int $proposalId, int $documentId,
        string $reference, string $bytes, string $confirmation, DateTimeImmutable $now): array
    {
        $reference = mb_strtoupper(trim($reference));
        $confirmation = trim($confirmation);
        $this->validateDocumentInput($reference, $bytes);
        if ($documentId <= 0 || mb_strlen($confirmation) < 20 || mb_strlen($confirmation) > 2000) {
            throw new DomainException('completed_refund_document_input');
        }
        return $this->withDocumentContext($actor, $uuid, $proposalId, $now,
            function (User $actor, AuditEvent $request, AuditEvent $closed, AuditEvent $proposal, array $excluded, string $date)
                use ($documentId, $reference, $bytes, $confirmation): array {
                $document = AuditEvent::query()->where('event', self::DOCUMENT_RECORDED)->whereKey($documentId)->lockForUpdate()->first();
                $p = $document?->after;
                $digest = hash('sha256', $bytes);
                if ($document === null || ! is_array($p) || $document->entity_type !== 'completed_cash_refund_request'
                    || $document->source !== 'internal' || $document->entity_id !== $digest
                    || ($p['sha256'] ?? null) !== $digest || ($p['byte_count'] ?? null) !== strlen($bytes)
                    || ($p['document_reference'] ?? null) !== $reference || (int) $document->id <= (int) $proposal->id
                    || ! is_string($p['recorded_on'] ?? null) || $p['recorded_on'] > $date
                    || in_array((int) $document->actor_user_id, $excluded, true)) {
                    throw new DomainException('completed_refund_document_conflict');
                }
                foreach ($this->documentIdentity($request, $closed, $proposal) as $key => $value) {
                    if (($p[$key] ?? null) !== $value) throw new DomainException('completed_refund_document_conflict');
                }
                if (in_array((int) $actor->id, [...$excluded, (int) $document->actor_user_id], true)) {
                    throw new DomainException('completed_refund_document_independence');
                }
                $proof = ['document_id' => (int) $document->id, 'document_hash' => $this->proposalHash($p),
                    'sha256' => $digest, 'operation_uuid' => $request->entity_id, 'proposal_id' => (int) $proposal->id,
                    'reviewed_on' => $date, 'confirmation' => $confirmation,
                    'bytes_matched' => true, 'external_authenticity_verified' => false, 'closure_authorized' => false];
                $rows = AuditEvent::query()->where('event', self::DOCUMENT_REVIEWED)
                    ->where('entity_id', $digest)->lockForUpdate()->get();
                if ($rows->count() > 1) throw new DomainException('completed_refund_document_conflict');
                $old = $rows->first();
                if ($old !== null) {
                    if ((int) $old->actor_user_id !== (int) $actor->id || $old->source !== 'internal'
                        || $old->entity_type !== 'completed_cash_refund_request' || (int) $old->id <= (int) $document->id
                        || ! is_array($old->after) || $this->proposalHash($old->after) !== $this->proposalHash($proof)) {
                        throw new DomainException('completed_refund_document_conflict');
                    }
                    return $this->documentResult($document, false, true);
                }
                app(AuditLogger::class)->recordRequired(self::DOCUMENT_REVIEWED, [
                    'entity_type' => 'completed_cash_refund_request', 'entity_id' => $digest,
                    'actor_user_id' => $actor->id, 'source' => 'internal', 'after' => $proof]);
                return $this->documentResult($document, true, true);
            });
    }

    /**
     * Canonical signed source statement, version 1. The signer must independently
     * check the actual cash fact. A trusted signature authenticates the statement,
     * not the physical event itself. Never use this API as permission to spend.
     */
    public static function sourceStatement(array $document, string $issuer, string $sourceReference): string
    {
        ksort($document, SORT_STRING);
        return json_encode([
            'purpose' => 'water.completed-cash-refund.source-attestation.v1',
            'issuer' => $issuer, 'source_reference' => $sourceReference,
            'document' => $document,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function attestResolutionSource(User $actor, string $uuid, int $proposalId, int $documentId,
        string $issuer, string $sourceReference, string $signature, string $substantiveReview,
        DateTimeImmutable $now): array
    {
        $substantiveReview = trim($substantiveReview);
        if ($documentId <= 0 || ! preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/', $issuer)
            || ! preg_match('/\A[A-Z0-9][A-Z0-9._\/-]{2,149}\z/', $sourceReference)
            || mb_strlen($substantiveReview) < 20 || mb_strlen($substantiveReview) > 2000) {
            throw new DomainException('completed_refund_source_input');
        }
        return $this->withDocumentContext($actor, $uuid, $proposalId, $now,
            function (User $actor, AuditEvent $request, AuditEvent $closed, AuditEvent $proposal, array $excluded, string $date)
                use ($documentId, $issuer, $sourceReference, $signature, $substantiveReview): array {
                [$proof, $document, $review] = $this->sourceProofLocked($request, $closed, $proposal, $excluded,
                    (int) $actor->id, $documentId, $issuer, $sourceReference, $signature, $substantiveReview, $date);
                $p = $document->after;
                $fail = 'completed_refund_source_evidence';
                foreach (AuditEvent::query()->where('event', self::SOURCE_ATTESTED)->orderBy('id')->lockForUpdate()->get() as $old) {
                    $q = $old->after;
                    if (! is_array($q) || ! is_string($q['issuer'] ?? null) || ! is_string($q['source_reference'] ?? null)
                        || ! is_int($q['document_id'] ?? null)) throw new DomainException($fail);
                    if ($q['document_id'] !== $documentId
                        && ! ($q['issuer'] === $issuer && $q['source_reference'] === $sourceReference)) continue;
                    if ((int) $old->actor_user_id !== (int) $actor->id || $old->source !== 'internal'
                        || $old->entity_type !== 'completed_cash_refund_request' || $old->entity_id !== $p['sha256']
                        || (int) $old->id <= (int) $review->id || $this->proposalHash($q) !== $this->proposalHash($proof)) {
                        throw new DomainException('completed_refund_source_conflict');
                    }
                    return ['created' => false, 'attestation_id' => (int) $old->id, 'attestation' => $proof,
                        'incident_unresolved' => true, 'financial_posting_created' => false, 'spending_authorized' => false];
                }
                $row = app(AuditLogger::class)->recordRequired(self::SOURCE_ATTESTED, [
                    'entity_type' => 'completed_cash_refund_request', 'entity_id' => $p['sha256'],
                    'actor_user_id' => $actor->id, 'source' => 'internal', 'after' => $proof]);
                return ['created' => true, 'attestation_id' => (int) $row->id, 'attestation' => $proof,
                    'incident_unresolved' => true, 'financial_posting_created' => false, 'spending_authorized' => false];
            });
    }

    /** Shared exact validation for recording and pending-use revalidation. */
    private function sourceProofLocked(AuditEvent $request, AuditEvent $closed, AuditEvent $proposal,
        array $excluded, int $attesterId, int $documentId, string $issuer, string $sourceReference,
        string $signature, string $substantiveReview, string $date, ?string $historicalFingerprint = null, ?string $approvalKey = null): array
    {
        $fail = 'completed_refund_source_evidence';
        $document = AuditEvent::query()->where('event', self::DOCUMENT_RECORDED)->whereKey($documentId)->lockForUpdate()->first();
        $p = $document?->after;
        if ($document === null || ! is_array($p) || count($p) !== 14
            || $document->source !== 'internal' || $document->entity_type !== 'completed_cash_refund_request'
            || ! is_string($p['sha256'] ?? null) || ! preg_match('/\A[0-9a-f]{64}\z/', $p['sha256'])
            || $document->entity_id !== $p['sha256'] || (int) $document->id <= (int) $proposal->id
            || ! is_int($p['byte_count'] ?? null) || $p['byte_count'] < 20 || $p['byte_count'] > 5 * 1024 * 1024
            || ! is_string($p['document_reference'] ?? null)
            || ! is_string($p['recorded_on'] ?? null) || $p['recorded_on'] > $date
            || (int) $document->actor_user_id <= 0 || in_array((int) $document->actor_user_id, $excluded, true)) {
            throw new DomainException($fail);
        }
        foreach ($this->documentIdentity($request, $closed, $proposal) as $key => $value) {
            if (($p[$key] ?? null) !== $value) throw new DomainException($fail);
        }
        $rows = AuditEvent::query()->where('event', self::DOCUMENT_REVIEWED)->where('entity_id', $p['sha256'])->lockForUpdate()->get();
        if ($rows->count() !== 1) throw new DomainException($fail);
        $review = $rows->first();
        $r = $review->after;
        if (! is_array($r) || count($r) !== 10
            || $review->source !== 'internal' || $review->entity_type !== 'completed_cash_refund_request'
            || (int) $review->id <= (int) $document->id
            || (int) $review->actor_user_id <= 0
            || in_array((int) $review->actor_user_id, [...$excluded, (int) $document->actor_user_id], true)
            || ($r['document_id'] ?? null) !== $documentId || ($r['document_hash'] ?? null) !== $this->proposalHash($p)
            || ($r['sha256'] ?? null) !== $p['sha256'] || ($r['operation_uuid'] ?? null) !== $request->entity_id
            || ($r['proposal_id'] ?? null) !== (int) $proposal->id
            || ($r['bytes_matched'] ?? null) !== true || ($r['external_authenticity_verified'] ?? null) !== false
            || ($r['closure_authorized'] ?? null) !== false || ! is_string($r['reviewed_on'] ?? null)
            || $r['reviewed_on'] < $p['recorded_on'] || $r['reviewed_on'] > $date
            || ! is_string($r['confirmation'] ?? null) || mb_strlen(trim($r['confirmation'])) < 20) {
            throw new DomainException($fail);
        }
        if (in_array($attesterId, [...$excluded, (int) $document->actor_user_id, (int) $review->actor_user_id], true)) {
            throw new DomainException('completed_refund_document_independence');
        }
        // Trust anchors are deployment configuration, never request data.
        $trusted = config('financial_candidate.refund_source_public_keys', []);
        $encodedKey = is_array($trusted) ? ($trusted[$issuer] ?? null) : null;
        if ($approvalKey !== null) {
            if ($historicalFingerprint === null) throw new DomainException('completed_refund_source_evidence');
            $encodedKey = $approvalKey;
        } elseif ($historicalFingerprint !== null) {
            // Historical keys are provisioned independently by deployment.
            // They are never considered by the live verification path.
            if (! preg_match('/\A[0-9a-f]{64}\z/', $historicalFingerprint)) {
                throw new DomainException('completed_refund_source_evidence');
            }
            $current = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;
            if ($current === false || ! hash_equals($historicalFingerprint, hash('sha256', $current))) {
                $archive = config('financial_candidate.refund_source_archived_public_keys', []);
                $encodedKey = is_array($archive) && is_array($archive[$issuer] ?? null)
                    ? ($archive[$issuer][$historicalFingerprint] ?? null) : null;
            }
        }
        if (! function_exists('sodium_crypto_sign_verify_detached') || ! is_string($encodedKey)) {
            throw new DomainException('completed_refund_source_untrusted');
        }
        $key = base64_decode($encodedKey, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || base64_encode($key) !== $encodedKey
            || ($historicalFingerprint !== null && ! hash_equals($historicalFingerprint, hash('sha256', $key)))) {
            throw new DomainException('completed_refund_source_untrusted');
        }
        $sig = strlen($signature) === 88 ? base64_decode($signature, true) : false;
        $statement = self::sourceStatement($p, $issuer, $sourceReference);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || base64_encode($sig) !== $signature
            || ! sodium_crypto_sign_verify_detached($sig, $statement, $key)) {
            throw new DomainException('completed_refund_source_signature');
        }
        $proof = ['document_id' => $documentId, 'document_hash' => $this->proposalHash($p),
            'byte_review_id' => (int) $review->id, 'byte_review_hash' => $this->proposalHash($r),
            'operation_uuid' => $request->entity_id, 'proposal_id' => (int) $proposal->id,
            'issuer' => $issuer, 'source_reference' => $sourceReference,
            'public_key_sha256' => hash('sha256', $key), 'statement_sha256' => hash('sha256', $statement),
            'signature' => $signature, 'substantive_review' => $substantiveReview, 'attested_on' => $date,
            'trusted_signature_verified' => true, 'physical_fact_automatically_verified' => false,
            'closure_authorized' => false];
        return [$proof, $document, $review, $encodedKey];
    }

    /**
     * Non-operative, current-trust check for an unresolved claim. Revocation or
     * rotation invalidates pending evidence, never deletes the historical audit.
     * This method deliberately does not reinterpret already posted history.
     */
    public function revalidateResolutionSource(User $actor, string $uuid, int $proposalId, int $attestationId,
        DateTimeImmutable $now): array
    {
        if ($attestationId <= 0) throw new DomainException('completed_refund_source_input');
        return $this->withDocumentContext($actor, $uuid, $proposalId, $now,
            function (User $actor, AuditEvent $request, AuditEvent $closed, AuditEvent $proposal, array $excluded, string $date)
                use ($attestationId): array {
                return $this->revalidateSourceProofLocked($actor, $request, $closed, $proposal, $excluded, $date, $attestationId);
            });
    }

    /**
     * Historical inspection remains non-operative and can inspect a superseded
     * proposal without opening its financial period. Never use the result to post.
     */
    public function inspectHistoricalResolutionSource(User $actor, string $uuid, int $proposalId, int $attestationId,
        DateTimeImmutable $now): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        if ($proposalId <= 0 || $attestationId <= 0 || DB::transactionLevel() !== 0) {
            throw new DomainException('completed_refund_source_input');
        }
        app(TreasuryRegisterService::class)->authorize($actor);
        $date = $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return DB::transaction(function () use ($actor, $uuid, $proposalId, $attestationId, $date): array {
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new DomainException('gated_refund_issuance_lock');
            }
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            self::authenticated($actor);
            app(TreasuryRegisterService::class)->authorize($actor);
            $this->guard();
            return $this->documentContext($actor, $uuid, $proposalId, $date,
                function (User $actor, AuditEvent $request, AuditEvent $closed, AuditEvent $proposal, array $excluded, string $date)
                    use ($attestationId): array {
                    return $this->revalidateSourceProofLocked($actor, $request, $closed, $proposal, $excluded, $date, $attestationId, true);
                }, true);
        });
    }

    private function revalidateSourceProofLocked(User $actor, AuditEvent $request, AuditEvent $closed, AuditEvent $proposal,
        array $excluded, string $date, int $attestationId, bool $historical = false, ?string $approvalKey = null): array
    {
        if (! $historical) FinancialWriteGate::currentContext();
        elseif (DB::transactionLevel() <= 0) throw new DomainException('completed_refund_source_evidence');
        $fail = 'completed_refund_source_evidence';
        $audit = AuditEvent::query()->where('event', self::SOURCE_ATTESTED)->whereKey($attestationId)
            ->lockForUpdate()->first();
        $q = $audit?->after;
        if ($audit === null || ! is_array($q) || count($q) !== 16
            || $audit->entity_type !== 'completed_cash_refund_request' || $audit->source !== 'internal'
            || (int) $audit->actor_user_id <= 0 || ($q['operation_uuid'] ?? null) !== $request->entity_id
            || ($q['proposal_id'] ?? null) !== (int) $proposal->id || ! is_int($q['document_id'] ?? null)
            || $q['document_id'] <= 0 || ! is_string($q['issuer'] ?? null)
            || ! preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/', $q['issuer'])
            || ! is_string($q['source_reference'] ?? null)
            || ! preg_match('/\A[A-Z0-9][A-Z0-9._\/-]{2,149}\z/', $q['source_reference'])
            || ! is_string($q['public_key_sha256'] ?? null) || ! preg_match('/\A[0-9a-f]{64}\z/', $q['public_key_sha256'])
            || ! is_string($q['signature'] ?? null) || ! is_string($q['substantive_review'] ?? null)
            || mb_strlen(trim($q['substantive_review'])) < 20 || mb_strlen($q['substantive_review']) > 2000
            || ! is_string($q['attested_on'] ?? null) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $q['attested_on'])
            || $q['attested_on'] > $date) {
            throw new DomainException($fail);
        }
        // Reconstruct the original signed statement and compare every
        // recorded field. Consult the current configured public key;
        // never trust a caller-provided key or stored success flag.
        [$expected, $document, $review, $verifiedKey] = $this->sourceProofLocked($request, $closed, $proposal, $excluded,
            (int) $audit->actor_user_id, $q['document_id'], $q['issuer'], $q['source_reference'],
            $q['signature'], $q['substantive_review'], $q['attested_on'], $historical ? $q['public_key_sha256'] : null, $approvalKey);
        if ($audit->entity_id !== $document->after['sha256'] || (int) $audit->id <= (int) $review->id
            || $this->proposalHash($q) !== $this->proposalHash($expected)) {
            throw new DomainException($fail);
        }
        if (in_array((int) $actor->id, [...$excluded, (int) $document->actor_user_id,
            (int) $review->actor_user_id, (int) $audit->actor_user_id], true)) {
            throw new DomainException('completed_refund_document_independence');
        }
        foreach (AuditEvent::query()->where('event', self::SOURCE_ATTESTED)->orderBy('id')->lockForUpdate()->get() as $other) {
            if ((int) $other->id === (int) $audit->id) continue;
            $p = $other->after;
            if (! is_array($p) || ! is_int($p['document_id'] ?? null)
                || ! is_string($p['issuer'] ?? null) || ! is_string($p['source_reference'] ?? null)
                || $p['document_id'] === $q['document_id']
                || ($p['issuer'] === $q['issuer'] && $p['source_reference'] === $q['source_reference'])) {
                throw new DomainException('completed_refund_source_conflict');
            }
        }
        if ($historical) {
            return ['attestation_id' => (int) $audit->id, 'attestation_hash' => $this->proposalHash($q),
                'proposal_id' => (int) $proposal->id, 'operation_uuid' => $request->entity_id,
                'public_key_sha256' => $q['public_key_sha256'], 'verified_public_key' => $verifiedKey, 'historical_signature_verified' => true,
                'current_trust_revalidated' => false, 'historical_trust_at_approval_proven' => false,
                'physical_fact_automatically_verified' => false, 'financial_posting_created' => false,
                'spending_authorized' => false, 'closure_authorized' => false];
        }
        return ['attestation_id' => (int) $audit->id, 'attestation_hash' => $this->proposalHash($q),
            'proposal_id' => (int) $proposal->id, 'operation_uuid' => $request->entity_id,
            'public_key_sha256' => $q['public_key_sha256'], 'verified_public_key' => $verifiedKey, 'trusted_signature_revalidated' => true,
            'physical_fact_automatically_verified' => false, 'financial_posting_created' => false,
            'spending_authorized' => false, 'closure_authorized' => false, 'incident_unresolved' => true];
    }

    private function validateDocumentInput(string $reference, string $bytes): void
    {
        if (! preg_match('/\A[A-Z0-9][A-Z0-9._\/-]{2,149}\z/', $reference)
            || strlen($bytes) < 20 || strlen($bytes) > 5 * 1024 * 1024) {
            throw new DomainException('completed_refund_document_input');
        }
    }

    private function withDocumentContext(User $actor, string $uuid, int $proposalId, DateTimeImmutable $now, callable $action): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        if ($proposalId <= 0) throw new DomainException('completed_refund_document_input');
        $date = $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now,
            function () use ($actor, $uuid, $proposalId, $date, $action): array {
                DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
                if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new DomainException('gated_refund_issuance_lock');
                }
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                self::authenticated($actor);
                app(TreasuryRegisterService::class)->authorize($actor);
                $this->guard();
                return $this->withDocumentContextLocked($actor, $uuid, $proposalId, $date, $action);
            });
    }

    private function withDocumentContextLocked(User $actor, string $uuid, int $proposalId, string $date, callable $action): array
    {
        $context = FinancialWriteGate::currentContext();
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        app(TreasuryRegisterService::class)->authorize($actor);
        $this->guard();
        if ($context['scope'] !== 'water' || $context['effective_on'] !== $date || $proposalId <= 0
            || ! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new DomainException('completed_refund_document_input');
        }
        return $this->documentContext($actor, $uuid, $proposalId, $date, $action);
    }

    private function documentContext(User $actor, string $uuid, int $proposalId, string $date, callable $action,
        bool $historical = false): array
    {
        if (DB::transactionLevel() <= 0) throw new DomainException('completed_refund_document_input');
        $request = $this->find($uuid, true);
        abort_unless($request !== null, 404);
        $p = $request->after;
        $this->assertIntent($request, self::intent($p['payment_id'], $p['account_id'], $p['amount'],
            $p['reference'], $p['date'], $p['reason'], $p['evidence'], $uuid, (int) $request->actor_user_id));
        $closed = $this->decision($request);
        if ($closed === null || (! $historical && ($this->verifiedResolution($request, $closed) !== null
            || DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first()))) {
            throw new DomainException('completed_refund_document_conflict');
        }
        $chain = $this->proposalChain($request, $closed);
        if ($chain === []) throw new DomainException('completed_refund_resolution_evidence');
        if ($historical) {
            // Include only the chain prefix that existed for this proposal.
            $prefix = [];
            $proposal = null;
            foreach ($chain as $item) {
                $prefix[] = $item;
                if ((int) $item->id === $proposalId) {
                    $proposal = $item;
                    break;
                }
            }
            if ($proposal === null) throw new DomainException('completed_refund_resolution_proposal_changed');
            $chain = $prefix;
        } else {
            $proposal = end($chain);
            $this->assertExpectedProposal($proposal, $proposalId);
        }
        $q = $proposal->after;
        if (! in_array($q['classification'] ?? null, ['posting_required', 'cash_returned', 'no_disbursement'], true)
            || ($q['request_id'] ?? null) !== (int) $request->id || ($q['decision_id'] ?? null) !== (int) $closed->id
            || ($q['operation_uuid'] ?? null) !== $uuid || ($q['account_id'] ?? null) !== $p['account_id']
            || ($q['payment_id'] ?? null) !== $p['payment_id'] || ($q['claimed_amount'] ?? null) !== $p['amount']
            || ($q['reference'] ?? null) !== $p['reference']) throw new DomainException('completed_refund_document_conflict');
        $groupIds = $this->verifyGroupRevisionMembership($p['account_id'], $q['count_id'], null, ! $historical);
        if ($groupIds !== [] && ($groupIds[$uuid] ?? null) !== (int) $proposal->id) {
            throw new DomainException('completed_refund_group_revision_evidence');
        }
        $payment = Payment::query()->lockForUpdate()->findOrFail($p['payment_id']);
        $count = DB::table('treasury_cash_counts')->where('id', $q['count_id'])->lockForUpdate()->first();
        $review = DB::table('treasury_cash_count_reviews')->where('id', $q['review_id'])->lockForUpdate()->first();
        if (! $count || ! $review || (int) $count->treasury_account_id !== $p['account_id']
            || (int) $review->treasury_cash_count_id !== (int) $count->id || ! (bool) $review->accepted) {
            throw new DomainException('completed_refund_document_conflict');
        }
        $excluded = [(int) $request->actor_user_id, (int) $closed->actor_user_id, (int) $payment->user_id,
            (int) $count->counted_by, (int) $review->reviewed_by];
        foreach ($chain as $item) $excluded[] = (int) $item->actor_user_id;
        if ($payment->cash_session_id !== null) {
            $excluded[] = (int) \App\Models\CashSession::query()->lockForUpdate()->findOrFail($payment->cash_session_id)->user_id;
        }
        return $action($actor, $request, $closed, $proposal, $excluded, $date);
    }

    private function documentIdentity(AuditEvent $request, AuditEvent $closed, AuditEvent $proposal): array
    {
        $p = $request->after;
        return ['request_id' => (int) $request->id, 'decision_id' => (int) $closed->id,
            'proposal_id' => (int) $proposal->id, 'proposal_hash' => $this->proposalHash($proposal->after),
            'operation_uuid' => $request->entity_id, 'payment_id' => $p['payment_id'],
            'account_id' => $p['account_id'], 'claimed_amount' => $p['amount'], 'refund_reference' => $p['reference'],
            'classification' => $proposal->after['classification']];
    }

    private function documentResult(AuditEvent $document, bool $created, bool $reviewed): array
    {
        return ['created' => $created, 'document_id' => (int) $document->id, 'document' => $document->after,
            'independent_byte_review_recorded' => $reviewed, 'external_authenticity_verified' => false,
            'original_bytes_stored' => false, 'financial_posting_created' => false,
            'spending_authorized' => false, 'closure_authorized' => false, 'incident_unresolved' => true];
    }

    private function proposalHash(array $proof): string
    {
        ksort($proof);
        return hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR));
    }

    /** Append-only linear chain. Never silently select a fork or malformed revision. */
    private function proposalChain(AuditEvent $request, AuditEvent $closed): array
    {
        $rows = AuditEvent::query()->where('event', self::RESOLUTION_PROPOSED)
            ->where('entity_id', $request->entity_id)->lockForUpdate()->get();
        $revisions = AuditEvent::query()->where('event', self::RESOLUTION_REVISED)
            ->where('entity_id', 'like', $request->entity_id.':%')->orderBy('id')->lockForUpdate()->get();
        if ($rows->count() > 1 || ($rows->isEmpty() && $revisions->isNotEmpty())) {
            throw new DomainException('completed_refund_resolution_conflict');
        }
        $chain = $rows->all();
        foreach ($revisions as $revision) {
            $previous = end($chain);
            $p = $revision->after;
            if (! is_array($p) || ! is_array($previous->after)
                || $revision->entity_type !== 'completed_cash_refund_request' || $revision->source !== 'internal'
                || (int) $revision->actor_user_id <= 0 || (int) $revision->id <= (int) $previous->id
                || ($p['request_id'] ?? null) !== (int) $request->id
                || ($p['decision_id'] ?? null) !== (int) $closed->id
                || ($p['operation_uuid'] ?? null) !== $request->entity_id
                || ($p['supersedes_id'] ?? null) !== (int) $previous->id
                || ($p['supersedes_hash'] ?? null) !== $this->proposalHash($previous->after)
                || ! is_int($p['count_id'] ?? null) || $p['count_id'] <= ($previous->after['count_id'] ?? PHP_INT_MAX)
                || ! is_int($p['count_audit_id'] ?? null) || $p['count_audit_id'] <= (int) $previous->id
                || $revision->entity_id !== $request->entity_id.':'.$p['count_id']) {
                throw new DomainException('completed_refund_resolution_conflict');
            }
            $chain[] = $revision;
        }
        return $chain;
    }

    /**
     * Reconstruct a renewal's complete immutable membership from the count-keyed
     * revision rows and exact predecessor chains. Never trust matching hashes
     * alone. Initial proposals and an ordinary single-claim revision retain
     * their original format. Historical reconstruction does not require a fresh
     * count, current keys, or the absence of a later valid revision.
     */
    private function verifyGroupRevisionMembership(int $accountId, int $countId, ?array $selection = null,
        bool $requireCurrent = false): array
    {
        $fail = 'completed_refund_group_revision_evidence';
        if (DB::transactionLevel() <= 0 || $accountId <= 0 || $countId <= 0) throw new DomainException($fail);
        $rows = AuditEvent::query()->where('event', self::RESOLUTION_REVISED)
            ->where('entity_id', 'like', '%:'.$countId)->orderBy('id')->lockForUpdate()->get();
        if ($rows->isEmpty()) return [];
        $grouped = $rows->filter(fn (AuditEvent $row) => is_array($row->after)
            && array_key_exists('group_revision_hash', $row->after));
        if ($grouped->isEmpty() && $rows->count() === 1) {
            $row = $rows->first();
            $q = $row->after;
            if (! is_array($q) || ($q['account_id'] ?? null) !== $accountId
                || ($q['count_id'] ?? null) !== $countId || ! is_string($q['operation_uuid'] ?? null)
                || $row->entity_id !== $q['operation_uuid'].':'.$countId
                || ($selection !== null && $selection !== [$q['operation_uuid'] => (int) $row->id])) {
                throw new DomainException($fail);
            }
            return [];
        }
        if ($rows->count() < 2 || $rows->count() > 50 || $grouped->count() !== $rows->count()) {
            throw new DomainException($fail);
        }
        $binding = null;
        $priorCount = null;
        $hash = null;
        $predecessors = $ids = [];
        $total = $posting = 0;
        foreach ($rows as $row) {
            $q = $row->after;
            if (! is_array($q) || ! is_string($q['operation_uuid'] ?? null)
                || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $q['operation_uuid'])
                || ($q['account_id'] ?? null) !== $accountId || ($q['count_id'] ?? null) !== $countId
                || ! is_string($q['group_revision_hash'] ?? null)
                || ! preg_match('/\A[0-9a-f]{64}\z/', $q['group_revision_hash'])
                || ! is_string($q['proposed_on'] ?? null) || ! is_string($q['evidence'] ?? null)
                || mb_strlen(trim($q['evidence'])) < 20 || mb_strlen($q['evidence']) > 2000
                || ! in_array($q['classification'] ?? null, ['posting_required', 'cash_returned', 'no_disbursement'], true)
                || $row->entity_type !== 'completed_cash_refund_request' || $row->source !== 'internal'
                || (int) $row->actor_user_id <= 0) throw new DomainException($fail);
            $uuid = $q['operation_uuid'];
            if (isset($ids[$uuid]) || $row->entity_id !== $uuid.':'.$countId) throw new DomainException($fail);
            $request = $this->find($uuid, true);
            if ($request === null) throw new DomainException($fail);
            $p = $request->after;
            if (($p['account_id'] ?? null) !== $accountId || ($p['date'] ?? null) !== $q['proposed_on']
                || ($q['payment_id'] ?? null) !== $p['payment_id'] || ($q['claimed_amount'] ?? null) !== $p['amount']
                || ($q['reference'] ?? null) !== $p['reference'] || ($q['incident_unresolved'] ?? null) !== true) {
                throw new DomainException($fail);
            }
            $this->assertIntent($request, self::intent($p['payment_id'], $p['account_id'], $p['amount'],
                $p['reference'], $p['date'], $p['reason'], $p['evidence'], $uuid, (int) $request->actor_user_id));
            $closed = $this->decision($request);
            if ($closed === null) throw new DomainException($fail);
            $chain = $this->proposalChain($request, $closed);
            $previous = null;
            foreach ($chain as $i => $entry) {
                if ((int) $entry->id === (int) $row->id && $i > 0) {
                    $previous = $chain[$i - 1];
                    break;
                }
            }
            if ($previous === null || ($requireCurrent && (int) end($chain)->id !== (int) $row->id)
                || ($previous->after['classification'] ?? null) !== $q['classification']
                || ! is_int($previous->after['count_id'] ?? null)
                || ($priorCount !== null && $priorCount !== $previous->after['count_id'])) {
                throw new DomainException($fail);
            }
            $priorCount = $previous->after['count_id'];
            $header = ['version' => 1, 'account_id' => $accountId, 'count_id' => $countId,
                'actor_id' => (int) $row->actor_user_id, 'date' => $q['proposed_on'], 'evidence' => $q['evidence']];
            if (($binding !== null && $binding !== $header) || ($hash !== null && $hash !== $q['group_revision_hash'])) {
                throw new DomainException($fail);
            }
            $binding = $header;
            $hash = $q['group_revision_hash'];
            $predecessors[$uuid] = ['id' => (int) $previous->id,
                'hash' => $this->proposalHash($previous->after), 'classification' => $q['classification']];
            $ids[$uuid] = (int) $row->id;
            $minor = FinancialAmount::parse($p['amount'])->minor;
            $total = \App\Support\SignedMinor::add($total, $minor);
            if ($q['classification'] === 'posting_required') $posting = \App\Support\SignedMinor::add($posting, $minor);
        }
        ksort($predecessors);
        ksort($ids);
        if ($selection !== null) {
            ksort($selection);
            if ($selection !== $ids) throw new DomainException($fail);
        }
        $binding += ['total_claimed_minor' => $total, 'posting_required_minor' => $posting, 'predecessors' => $predecessors];
        if (! hash_equals($hash, $this->proposalHash($binding))) throw new DomainException($fail);
        foreach ($rows as $row) {
            if (($row->after['variance_minor'] ?? null) !== -$posting) throw new DomainException($fail);
        }
        return $ids;
    }

    private function assertExpectedProposal(AuditEvent $proposal, ?int $expected): void
    {
        if (($proposal->event === self::RESOLUTION_REVISED && $expected === null)
            || ($expected !== null && $expected !== (int) $proposal->id)) {
            throw new DomainException('completed_refund_resolution_proposal_changed');
        }
    }

    /** Fingerprint historical source rows; never compare today's balance to an old count on replay. */
    private function resolutionSources(AuditEvent $request, AuditEvent $closed, int $approver, bool $postingRequired = false, bool $posted = false, ?int $batchVariance = null, bool $mixedReadiness = false): array
    {
        $fail = 'completed_refund_resolution_evidence';
        $p = $request->after;
        $this->assertIntent($request, self::intent($p['payment_id'], $p['account_id'], $p['amount'],
            $p['reference'], $p['date'], $p['reason'], $p['evidence'], $request->entity_id, (int) $request->actor_user_id));
        $chain = $this->proposalChain($request, $closed);
        if ($chain === []) throw new DomainException($fail);
        $proposal = end($chain);
        $q = $proposal->after;
        if ($batchVariance !== null && ($mixedReadiness ? ($batchVariance > 0) : (! $postingRequired || $batchVariance >= 0))) {
            throw new DomainException($fail);
        }
        if ($batchVariance === null && is_int($q['count_id'] ?? null)) {
            $this->verifyGroupRevisionMembership($p['account_id'], $q['count_id'], [$request->entity_id => (int) $proposal->id], true);
        }
        $expectedVariance = $batchVariance ?? ($postingRequired ? -FinancialAmount::parse($p['amount'])->minor : 0);
        if (! is_array($q) || $proposal->entity_type !== 'completed_cash_refund_request' || $proposal->source !== 'internal'
            || (int) $proposal->id <= (int) $closed->id || (int) $proposal->actor_user_id <= 0
            || ($q['request_id'] ?? null) !== (int) $request->id || ($q['decision_id'] ?? null) !== (int) $closed->id
            || ($q['operation_uuid'] ?? null) !== $request->entity_id || ($q['account_id'] ?? null) !== $p['account_id']
            || ($q['payment_id'] ?? null) !== $p['payment_id'] || ($q['claimed_amount'] ?? null) !== $p['amount']
            || ($q['reference'] ?? null) !== $p['reference'] || ($q['incident_unresolved'] ?? null) !== true
            || ! in_array($q['classification'] ?? null, $postingRequired ? ['posting_required'] : ['no_disbursement', 'cash_returned'], true)
            || ($q['variance_minor'] ?? null) !== $expectedVariance || ! is_int($q['counted_minor'] ?? null)
            || ! is_int($q['recorded_minor'] ?? null)
            || $q['counted_minor'] !== \App\Support\SignedMinor::add($q['recorded_minor'], $expectedVariance)
            || ! is_int($q['count_id'] ?? null) || ! is_int($q['review_id'] ?? null)
            || ! is_string($q['evidence'] ?? null) || mb_strlen(trim($q['evidence'])) < 20
            || ! is_string($q['proposed_on'] ?? null)) {
            throw new DomainException($fail);
        }
        $operation = DB::table('financial_posting_operations')->where('operation_uuid', $request->entity_id)->lockForUpdate()->first();
        $refund = PaymentRefund::query()->where('disbursement_reference', $p['reference'])->lockForUpdate()->first();
        if (($operation !== null) !== $posted || ($refund !== null) !== $posted || ($posted && ! $postingRequired)) {
            throw new DomainException($fail);
        }
        $payment = Payment::query()->lockForUpdate()->findOrFail($p['payment_id']);
        $count = DB::table('treasury_cash_counts')->where('id', $q['count_id'])->lockForUpdate()->first();
        $review = DB::table('treasury_cash_count_reviews')->where('id', $q['review_id'])->lockForUpdate()->first();
        $o = DB::table('audit_events')->where('id', $q['count_audit_id'])->lockForUpdate()->first();
        $r = DB::table('audit_events')->where('id', $q['review_audit_id'])->lockForUpdate()->first();
        if (! $count || ! $review || ! $o || ! $r
            || (int) $count->treasury_account_id !== $p['account_id']
            || (int) $review->treasury_cash_count_id !== (int) $count->id || ! (bool) $review->accepted
            || (int) $count->counted_by === (int) $review->reviewed_by
            || \App\Support\TreasuryMoney::document($count->counted_minor) !== $q['counted_minor']
            || \App\Support\TreasuryMoney::signed($count->recorded_minor) !== $q['recorded_minor']
            || \App\Support\TreasuryMoney::signed($count->variance_minor) !== $expectedVariance
            || $count->ledger_hash !== $q['ledger_hash']
            || $o->event !== 'treasury.cash_count_recorded' || $o->entity_type !== 'treasury_cash_count'
            || (string) $o->entity_id !== (string) $count->id || (int) $o->actor_user_id !== (int) $count->counted_by
            || $r->event !== 'treasury.cash_count_reviewed' || $r->entity_type !== 'treasury_cash_count_review'
            || (string) $r->entity_id !== (string) $review->id || (int) $r->actor_user_id !== (int) $review->reviewed_by
            || (int) $o->id <= (int) $closed->id || (int) $r->id <= (int) $o->id || (int) $proposal->id <= (int) $r->id
            || DB::table('treasury_cash_adjustments')->where('treasury_cash_count_id', $count->id)->lockForUpdate()->first()) {
            throw new DomainException($fail);
        }
        $excluded = [(int) $request->actor_user_id, (int) $payment->user_id];
        if ($payment->cash_session_id !== null) {
            $excluded[] = (int) \App\Models\CashSession::query()->lockForUpdate()->findOrFail($payment->cash_session_id)->user_id;
        }
        if (in_array((int) $proposal->actor_user_id, $excluded, true)
            || in_array((int) $count->counted_by, $excluded, true)
            || in_array((int) $review->reviewed_by, $excluded, true)
            || $approver <= 0 || in_array($approver, array_merge($excluded, [(int) $closed->actor_user_id,
                (int) $proposal->actor_user_id, (int) $count->counted_by, (int) $review->reviewed_by],
                array_map(fn (AuditEvent $item) => (int) $item->actor_user_id, $chain)), true)) {
            throw new DomainException('completed_refund_resolution_independence');
        }
        $observation = json_decode($o->after, true, 512, JSON_THROW_ON_ERROR);
        $reviewAudit = json_decode($r->after, true, 512, JSON_THROW_ON_ERROR);
        if (($observation['ledger_hash'] ?? null) !== $q['ledger_hash']
            || ($observation['counted_minor'] ?? null) !== $q['counted_minor']
            || ($observation['recorded_minor'] ?? null) !== $q['recorded_minor']
            || ($observation['variance_minor'] ?? null) !== $expectedVariance
            || ($observation['reference'] ?? null) !== $count->reference
            || ($observation['evidence'] ?? null) !== $count->evidence
            || (int) ($reviewAudit['treasury_cash_count_id'] ?? 0) !== (int) $count->id
            || ($reviewAudit['accepted'] ?? null) !== true
            || ($reviewAudit['evidence'] ?? null) !== $review->evidence
            || ($reviewAudit['financial_adjustment_created'] ?? null) !== false) {
            throw new DomainException($fail);
        }
        $fingerprint = static function (array $data): string {
            ksort($data);
            return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        };
        return ['proposal' => $proposal, 'identity' => [
            'request_id' => (int) $request->id, 'decision_id' => (int) $closed->id,
            'proposal_id' => (int) $proposal->id, 'operation_uuid' => $request->entity_id,
            'account_id' => $p['account_id'], 'count_id' => (int) $count->id, 'classification' => $q['classification'],
            'proposal_hash' => $fingerprint($q), 'count_hash' => $fingerprint((array) $count),
            'review_hash' => $fingerprint((array) $review), 'observation_hash' => $fingerprint((array) $o),
            'review_audit_hash' => $fingerprint((array) $r), 'financial_posting_created' => $posted,
        ]];
    }

    private function verifiedResolution(AuditEvent $request, AuditEvent $closed): ?AuditEvent
    {
        $rows = AuditEvent::query()->where('event', self::RESOLUTION_APPROVED)
            ->where('entity_id', $request->entity_id)->lockForUpdate()->get();
        if ($rows->count() > 1) throw new DomainException('completed_refund_resolution_conflict');
        $approval = $rows->first();
        if ($approval === null) return $this->verifiedBatchResolution($request, $closed);
        $p = $approval->after;
        $posted = ($p['financial_posting_created'] ?? null) === true;
        $sources = $this->resolutionSources($request, $closed, (int) $approval->actor_user_id, $posted, $posted);
        if ($approval->entity_type !== 'completed_cash_refund_request' || $approval->source !== 'internal'
            || (int) $approval->id <= (int) $sources['proposal']->id || ! is_array($p)
            || count($p) !== count($sources['identity']) + 2
            || ($p['approved_on'] ?? null) !== $sources['proposal']->after['proposed_on']
            || ! is_string($p['confirmation'] ?? null) || mb_strlen(trim($p['confirmation'])) < 20
            || mb_strlen($p['confirmation']) > 2000) {
            throw new DomainException('completed_refund_resolution_conflict');
        }
        foreach ($sources['identity'] as $key => $value) {
            if (! array_key_exists($key, $p) || $p[$key] !== $value) {
                throw new DomainException('completed_refund_resolution_conflict');
            }
        }
        if ($posted) {
            $this->verifyResolutionPosting($request->after, (int) $approval->actor_user_id, $p['confirmation']);
        }
        return $approval;
    }

    /** A closed review is not a cash recovery. Caller owns the issuance fence. */
    public function assertNoUnresolvedForAccount(int $accountId): void
    {
        if ($this->unresolvedForAccount($accountId)['count'] !== 0) {
            throw new DomainException('completed_cash_refund_incident_unresolved');
        }
    }

    /** Unverified cash assertions are a separate disclosure, not a booked balance. */
    public function unresolvedForAccount(int $accountId): array
    {
        if (DB::transactionLevel() <= 0
            || ! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new DomainException('held_cash_reservation_context');
        }
        $this->guard();
        $count = $claimed = 0;
        foreach (AuditEvent::query()->where('event', self::EVENT)->orderBy('id')->lockForUpdate()->get() as $row) {
            $p = $row->after;
            if (! is_array($p) || ! is_int($p['account_id'] ?? null) || $p['account_id'] <= 0
                || $row->entity_type !== 'completed_cash_refund_request') {
                throw new DomainException('completed_cash_refund_request_conflict');
            }
            if ($p['account_id'] !== $accountId) continue;
            $intent = self::intent($p['payment_id'], $p['account_id'], $p['amount'], $p['reference'],
                $p['date'], $p['reason'], $p['evidence'], $row->entity_id, (int) $row->actor_user_id);
            $this->assertIntent($row, $intent);
            $closed = $this->decision($row);
            if ($closed !== null && $this->verifiedResolution($row, $closed) !== null) continue;
            if ($closed !== null
                || ! DB::table('financial_posting_operations')->where('operation_uuid', $row->entity_id)->lockForUpdate()->first()) {
                $count++;
                $claimed = \App\Support\SignedMinor::add($claimed, FinancialAmount::parse($intent['amount'])->minor);
                continue;
            }
            app(GatedRefundWriter::class)->verifyIncidentPosting($intent);
        }
        return ['count' => $count, 'claimed_minor' => $claimed];
    }

    private function decision(AuditEvent $request): ?AuditEvent
    {
        $rows = AuditEvent::query()->where('event', self::CLOSED)
            ->where('entity_id', $request->entity_id)->lockForUpdate()->get();
        if ($rows->count() > 1) throw new DomainException('completed_cash_refund_decision_conflict');
        $row = $rows->first();
        if ($row === null) return null;
        $p = $row->after;
        if ($row->entity_type !== 'completed_cash_refund_request' || $row->source !== 'internal'
            || (int) $row->actor_user_id <= 0 || ! is_array($p) || count($p) !== 6
            || ($p['request_id'] ?? null) !== (int) $request->id
            || ($p['operation_uuid'] ?? null) !== $request->entity_id
            || ! in_array($p['decision'] ?? null, ['rejected', 'withdrawn'], true)
            || ! is_string($p['reason'] ?? null) || mb_strlen($p['reason']) < 20
            || ! is_string($p['decided_on'] ?? null) || ($p['incident_unresolved'] ?? null) !== true
            || (($p['decision'] === 'withdrawn') !== ((int) $request->actor_user_id === (int) $row->actor_user_id))) {
            throw new DomainException('completed_cash_refund_decision_conflict');
        }
        return $row;
    }

    private function status(AuditEvent $request): array
    {
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $request->entity_id)->exists();
        $closed = $this->decision($request);
        $resolution = $closed === null ? null : $this->verifiedResolution($request, $closed);
        if ($closed !== null && $op && ($resolution?->after['financial_posting_created'] ?? null) !== true) {
            throw new DomainException('completed_cash_refund_decision_conflict');
        }
        $chain = $closed === null ? [] : $this->proposalChain($request, $closed);
        $current = $chain === [] ? null : end($chain);
        if ($current !== null) {
            $q = $current->after;
            if (! is_int($q['count_id'] ?? null)) throw new DomainException('completed_refund_group_revision_evidence');
            $groupIds = $this->verifyGroupRevisionMembership($request->after['account_id'], $q['count_id'], null, true);
            if ($groupIds !== [] && ($groupIds[$request->entity_id] ?? null) !== (int) $current->id) {
                throw new DomainException('completed_refund_group_revision_evidence');
            }
        }
        // An operation UUID alone is not a verified posting.
        return ['status' => $closed?->after['decision'] ?? ($op ? 'posting_recorded_unverified' : 'pending'),
            'operation_exists' => $op, 'completion_verified' => $op && ($resolution?->after['financial_posting_created'] ?? null) === true,
            'incident_unresolved' => $resolution === null,
            'resolution' => $resolution === null ? null : $resolution->after + ['approved_by' => (int) $resolution->actor_user_id],
            'current_proposal_id' => $current === null ? null : (int) $current->id,
            'current_proposal' => $current?->after,
            'decision' => $closed === null ? null : $closed->after + ['decided_by' => (int) $closed->actor_user_id]];
    }

    public function approve(User $reviewer, string $uuid, string $confirmation, DateTimeImmutable $now): array
    {
        self::authenticated($reviewer);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        app(TreasuryRegisterService::class)->authorize($reviewer);
        $request = $this->find($uuid, false);
        abort_unless($request !== null, 404);
        $p = $request->after;
        if (! is_array($p)) throw new DomainException('completed_cash_refund_request_conflict');
        return app(GatedRefundWriter::class)->recordCompletedCashRefund(User::findOrFail($request->actor_user_id),
            $reviewer, $p['payment_id'], $p['account_id'], $p['amount'], $p['reference'], $p['date'],
            $p['reason'], $p['evidence'], $confirmation, $uuid, $now);
    }

    public function verifyLocked(array $intent): void
    {
        FinancialWriteGate::currentContext();
        $this->guard();
        $row = $this->find($intent['operation_uuid'], true);
        if ($row === null) throw new DomainException('completed_cash_refund_request_required');
        $this->assertIntent($row, $intent);
        if ($this->decision($row) !== null) throw new DomainException('completed_cash_refund_request_closed');
    }

    public function assertUnreserved(string $uuid, string $reference): void
    {
        FinancialWriteGate::currentContext();
        $this->guard();
        foreach (AuditEvent::query()->where('event', self::EVENT)->lockForUpdate()->get() as $row) {
            if ($row->entity_id === $uuid || ($row->after['reference'] ?? null) === $reference) {
                throw new DomainException('completed_cash_refund_request_conflict');
            }
        }
    }

    private function guard(): void
    {
        if (! app(CompletedCashRefundRequestProtection::class)->active()
            || ! app(CompletedCashRefundDecisionProtection::class)->active()
            || ! app(CompletedCashRefundResolutionProposalProtection::class)->active()
            || ! app(CompletedCashRefundResolutionApprovalProtection::class)->active()
            || ! app(CompletedCashRefundResolutionRevisionProtection::class)->active()
            || ! app(CompletedCashRefundBatchApprovalProtection::class)->active()
            || ! app(CompletedCashRefundDocumentProtection::class)->active()
            || ! app(CompletedCashRefundSourceProtection::class)->active()) {
            throw new DomainException('completed_cash_refund_request_protection_missing');
        }
    }

    private function find(string $uuid, bool $lock): ?AuditEvent
    {
        $query = AuditEvent::query()->where('event', self::EVENT)
            ->where('entity_type', 'completed_cash_refund_request')->where('entity_id', $uuid);
        if ($lock) $query->lockForUpdate();
        $rows = $query->get();
        if ($rows->count() > 1) throw new DomainException('completed_cash_refund_request_conflict');
        return $rows->first();
    }

    private function assertIntent(AuditEvent $row, array $intent): void
    {
        $actual = $row->after;
        if ((int) $row->actor_user_id !== $intent['requested_by'] || $row->source !== 'internal'
            || ! is_array($actual) || count($actual) !== count($intent)) {
            throw new DomainException('completed_cash_refund_request_conflict');
        }
        foreach ($intent as $key => $value) {
            if (($actual[$key] ?? null) !== $value) throw new DomainException('completed_cash_refund_request_conflict');
        }
    }
}
