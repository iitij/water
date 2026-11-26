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
                return ['operation_uuid' => $intent['operation_uuid'], 'created' => false];
            }
            $this->assertUnreserved($intent['operation_uuid'], $intent['reference']);
            if (PaymentRefund::query()->where('disbursement_reference', $intent['reference'])->exists()
                || DB::table('financial_posting_operations')->where('operation_uuid', $intent['operation_uuid'])->exists()) {
                throw new DomainException('completed_cash_refund_request_conflict');
            }
            app(AuditLogger::class)->recordRequired(self::EVENT, [
                'entity_type' => 'completed_cash_refund_request', 'entity_id' => $intent['operation_uuid'],
                'actor_user_id' => $actor->id, 'after' => $intent, 'source' => 'internal']);
            return ['operation_uuid' => $intent['operation_uuid'], 'created' => true];
        });
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
        if (! app(CompletedCashRefundRequestProtection::class)->active()) {
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
