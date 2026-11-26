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
            $this->guard();
            $row = $this->find($uuid, false);
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
            if ($this->decision($row) !== null
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
        // An operation UUID alone is not a verified posting.
        return ['status' => $closed?->after['decision'] ?? ($op ? 'posting_recorded_unverified' : 'pending'),
            'operation_exists' => $op, 'completion_verified' => false,
            'incident_unresolved' => true,
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
            || ! app(CompletedCashRefundDecisionProtection::class)->active()) {
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
