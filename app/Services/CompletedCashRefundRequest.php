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
    public const RESOLUTION_APPROVED = 'financial.completed_cash_refund_resolution_approved';

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
        string $evidence, DateTimeImmutable $now): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $evidence = trim($evidence);
        if ($countId <= 0 || ! in_array($classification, ['no_disbursement', 'cash_returned', 'posting_required'], true)
            || mb_strlen($evidence) < 20 || mb_strlen($evidence) > 2000) {
            throw new DomainException('completed_refund_resolution_input');
        }
        $date = $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now,
            function () use ($actor, $uuid, $countId, $classification, $evidence, $date): array {
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
                $existing = AuditEvent::query()->where('event', self::RESOLUTION_PROPOSED)
                    ->where('entity_id', $uuid)->lockForUpdate()->get();
                if ($existing->count() > 1) throw new DomainException('completed_refund_resolution_conflict');
                if ($existing->isNotEmpty()) {
                    $old = $existing->first();
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
                    return ['created' => false, 'resolution_proposal' => $proof, 'incident_unresolved' => true];
                }
                app(AuditLogger::class)->recordRequired(self::RESOLUTION_PROPOSED, [
                    'entity_type' => 'completed_cash_refund_request', 'entity_id' => $uuid,
                    'actor_user_id' => $actor->id, 'source' => 'internal', 'after' => $proof]);
                return ['created' => true, 'resolution_proposal' => $proof, 'incident_unresolved' => true];
            });
    }

    /** Narrow no-posting resolution. A counted balance is not a general certification. */
    public function approveResolution(User $actor, string $uuid, string $confirmation, DateTimeImmutable $now): array
    {
        self::authenticated($actor);
        abort_unless(app()->environment(['testing', 'staging']), 503);
        $confirmation = trim($confirmation);
        if (mb_strlen($confirmation) < 20 || mb_strlen($confirmation) > 2000) {
            throw new DomainException('completed_refund_resolution_input');
        }
        $date = $now->setTimezone(new \DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $date, $now,
            function () use ($actor, $uuid, $confirmation, $date): array {
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
                $old = $this->verifiedResolution($request, $closed);
                if ($old !== null) {
                    if ((int) $old->actor_user_id !== (int) $actor->id || $old->after['confirmation'] !== $confirmation) {
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
                app(TreasuryCashCountService::class)->assertFreshEqualObservation($accountId, $p['count_id'], $date);
                if ($p['proposed_on'] !== $date
                    || app(HeldCashRecordedLiquidityEvidence::class)->balance($accountId, $date) !== $p['counted_minor']) {
                    throw new DomainException('completed_refund_resolution_stale_count');
                }
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
                app(TreasuryCashCountService::class)->assertFreshObservationVariance(
                    $p['account_id'], $p['count_id'], $date, -$minor);
                $balance = app(HeldCashRecordedLiquidityEvidence::class)->balance($p['account_id'], $date);
                if ($balance !== $p['recorded_minor'] || $balance < $minor
                    || \App\Support\SignedMinor::subtract($balance, $minor) !== $p['counted_minor']) {
                    throw new DomainException('completed_refund_resolution_stale_count');
                }
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

    /** Fingerprint historical source rows; never compare today's balance to an old count on replay. */
    private function resolutionSources(AuditEvent $request, AuditEvent $closed, int $approver, bool $postingRequired = false): array
    {
        $fail = 'completed_refund_resolution_evidence';
        $p = $request->after;
        $this->assertIntent($request, self::intent($p['payment_id'], $p['account_id'], $p['amount'],
            $p['reference'], $p['date'], $p['reason'], $p['evidence'], $request->entity_id, (int) $request->actor_user_id));
        $proposals = AuditEvent::query()->where('event', self::RESOLUTION_PROPOSED)
            ->where('entity_id', $request->entity_id)->lockForUpdate()->get();
        if ($proposals->count() !== 1) throw new DomainException($fail);
        $proposal = $proposals->first();
        $q = $proposal->after;
        $expectedVariance = $postingRequired ? -FinancialAmount::parse($p['amount'])->minor : 0;
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
        if (DB::table('financial_posting_operations')->where('operation_uuid', $request->entity_id)->lockForUpdate()->first()
            || PaymentRefund::query()->where('disbursement_reference', $p['reference'])->lockForUpdate()->first()) {
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
                (int) $proposal->actor_user_id, (int) $count->counted_by, (int) $review->reviewed_by]), true)) {
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
            'review_audit_hash' => $fingerprint((array) $r), 'financial_posting_created' => false,
        ]];
    }

    private function verifiedResolution(AuditEvent $request, AuditEvent $closed): ?AuditEvent
    {
        $rows = AuditEvent::query()->where('event', self::RESOLUTION_APPROVED)
            ->where('entity_id', $request->entity_id)->lockForUpdate()->get();
        if ($rows->count() > 1) throw new DomainException('completed_refund_resolution_conflict');
        $approval = $rows->first();
        if ($approval === null) return null;
        $sources = $this->resolutionSources($request, $closed, (int) $approval->actor_user_id);
        $p = $approval->after;
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
        if ($closed !== null && $op) throw new DomainException('completed_cash_refund_decision_conflict');
        $resolution = $closed === null ? null : $this->verifiedResolution($request, $closed);
        // An operation UUID alone is not a verified posting.
        return ['status' => $closed?->after['decision'] ?? ($op ? 'posting_recorded_unverified' : 'pending'),
            'operation_exists' => $op, 'completion_verified' => false,
            'incident_unresolved' => $resolution === null,
            'resolution' => $resolution === null ? null : $resolution->after + ['approved_by' => (int) $resolution->actor_user_id],
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
            || ! app(CompletedCashRefundResolutionApprovalProtection::class)->active()) {
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
