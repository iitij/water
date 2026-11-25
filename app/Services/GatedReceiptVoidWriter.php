<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive candidate. Pending requests and rejections are decisions, not
 * ledger movements. Only completed voids receive posting operation UUIDs.
 * A request cannot bypass the existing approval threshold.
 */
final class GatedReceiptVoidWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private PaymentService $payments,
        private FinancialLedgerEvidence $ledger,
        private FinancialPostingRegistry $registry,
    ) {}

    public function request(User $actor, int $id, string $reason, DateTimeImmutable $now, ?string $uuid = null): array
    {
        return $this->within($actor, $id, $now, function (User $actor, Payment $payment, Subscriber $subscriber, array $context) use ($reason, $uuid, $now): array {
            abort_unless($actor->hasAnyRole(['super_admin', 'admin']) || $actor->can('Void:Payment') || $actor->can('void_payment'), 403);
            $reason = trim($reason);
            $this->need(mb_strlen($reason) >= 10 && mb_strlen($reason) <= 1000, 'gated_void_reason');
            if ($payment->isVoided()) {
                $this->need($payment->voided_via === 'gated' && (int) $payment->voided_by === (int) $actor->id
                    && $payment->void_reason === $reason, 'gated_void_replay_conflict');
                return $this->register($payment, $uuid, $context, $now, true);
            }
            $pending = $payment->hasPendingVoidRequest();
            if ($pending || $this->payments->requiresVoidApproval($payment)) {
                $this->need($uuid === null, 'gated_void_pending_no_posting_uuid');
                if ($pending) {
                    $this->need((int) $payment->void_requested_by === (int) $actor->id
                        && $payment->void_request_reason === $reason, 'gated_void_request_conflict');
                    $this->requestProof($payment);
                    return ['payment' => $payment, 'pending' => true, 'registry_created' => false];
                }
                $result = $this->payments->requestVoid($payment, $reason, (int) $actor->id, 'gated');
                $this->need($result['pending'], 'gated_void_expected_pending');
                app(AuditLogger::class)->recordRequired('financial.void_request_gated', [
                    'entity_type' => Payment::class, 'entity_id' => $payment->id, 'actor_user_id' => $actor->id,
                    'after' => ['reason' => $reason, 'requested_at' => $result['payment']->getRawOriginal('void_requested_at')],
                ]);
                $this->requestProof($result['payment']);
                return $result + ['registry_created' => false];
            }
            $this->unused($uuid);
            $before = app(TransactionService::class)->accountBalanceMinor($subscriber);
            $result = $this->payments->requestVoid($payment, $reason, (int) $actor->id, 'gated');
            $this->need(! $result['pending'], 'gated_void_expected_immediate');
            $this->checkBalance($result['payment'], $subscriber, $before);
            return $this->register($result['payment'], $uuid, $context, $now, false);
        });
    }

    public function decide(User $actor, int $id, bool $approve, ?string $notes, DateTimeImmutable $now, ?string $uuid = null): array
    {
        return $this->within($actor, $id, $now, function (User $actor, Payment $payment, Subscriber $subscriber, array $context) use ($approve, $notes, $uuid, $now): array {
            abort_unless($actor->hasAnyRole(['super_admin', 'admin']) || $actor->can('payments.void.approve')
                || $actor->can('Approve:Payment') || $actor->can('approve_payment'), 403);
            $notes = $notes === null ? null : trim($notes);
            $this->need($notes === null || mb_strlen($notes) <= 1000, 'gated_void_notes');
            if ($payment->isVoided()) {
                $this->need($approve && $payment->voided_via === 'approval'
                    && (int) $payment->void_decided_by === (int) $actor->id
                    && $payment->void_decision_notes === $notes, 'gated_void_replay_conflict');
                return $this->register($payment, $uuid, $context, $now, true);
            }
            $this->need($payment->hasPendingVoidRequest(), 'gated_void_pending_required');
            $this->requestProof($payment);
            abort_if((int) $payment->void_requested_by === (int) $actor->id, 403);
            if ($approve) $this->unused($uuid);
            else $this->need($uuid === null, 'gated_void_pending_no_posting_uuid');
            $before = app(TransactionService::class)->accountBalanceMinor($subscriber);
            $result = $this->payments->decideVoidRequest($payment, $approve, $actor, $notes, 'gated');
            if (! $approve) {
                $this->need(! $result->isVoided() && ! $result->hasPendingVoidRequest()
                    && app(TransactionService::class)->accountBalanceMinor($subscriber) === $before, 'gated_void_rejection_effect');
                return ['payment' => $result, 'pending' => false, 'registry_created' => false];
            }
            $this->checkBalance($result, $subscriber, $before);
            return $this->register($result, $uuid, $context, $now, false);
        });
    }

    private function within(User $actor, int $id, DateTimeImmutable $now, callable $action): array
    {
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return $this->gate->withinOpenPeriod('water', $date, $now, function (array $context) use ($actor, $id, $action): array {
            DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
            $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null, 'gated_void_issuance_lock');
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $candidate = Payment::query()->findOrFail($id);
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($id);
            $this->need((int) $payment->subscriber_id === (int) $subscriber->id && $payment->payment_method === 'cash', 'gated_void_payment_identity');
            $this->registered($this->ledger->leg((int) $this->entry($id, 'payment')->id), $payment->payment_date->toDateString());
            return $action($actor, $payment, $subscriber, $context);
        });
    }

    private function requestProof(Payment $payment): void
    {
        $rows = AuditEvent::query()->where('event', 'financial.void_request_gated')->where('entity_type', Payment::class)
            ->where('entity_id', (string) $payment->id)->orderBy('id')->lockForUpdate()->get();
        $proof = $rows->last();
        $this->need($proof !== null && (int) $proof->actor_user_id === (int) $payment->void_requested_by
            && ($proof->after['reason'] ?? null) === $payment->void_request_reason
            && ($proof->after['requested_at'] ?? null) === $payment->getRawOriginal('void_requested_at'), 'gated_void_request_unverified');
        $request = AuditEvent::query()->where('event', 'payment.void_requested')->where('entity_type', Payment::class)
            ->where('entity_id', (string) $payment->id)->orderBy('id')->lockForUpdate()->get()->last();
        $this->need($request !== null && (int) $request->id < (int) $proof->id
            && (int) $request->actor_user_id === (int) $payment->void_requested_by
            && ($request->after['reason'] ?? null) === $payment->void_request_reason, 'gated_void_request_unverified');
    }

    private function checkBalance(Payment $payment, Subscriber $subscriber, int $before): void
    {
        $this->need(app(TransactionService::class)->accountBalanceMinor($subscriber) === SignedMinor::add($before, -AccountMoney::stored($payment)), 'gated_void_balance');
    }

    private function register(Payment $payment, ?string $uuid, array $context, DateTimeImmutable $now, bool $replay): array
    {
        $this->validUuid($uuid);
        $this->need($payment->isVoided() && $payment->voided_at->toDateString() === $context['effective_on'], 'gated_void_effective_date');
        $leg = $this->ledger->leg((int) $this->entry((int) $payment->id, 'payment_reversal')->id);
        $this->need($leg['amount_minor'] === -AccountMoney::stored($payment), 'gated_void_effect_mismatch');
        if ($replay) {
            $op = $this->registered($leg, $context['effective_on']);
            $this->need($op->operation_uuid === $uuid, 'gated_void_operation_conflict');
        }
        $created = $this->registry->record($context, $uuid, [$leg], $now);
        $this->need($created === ! $replay, 'gated_void_operation_conflict');
        return ['payment' => $payment, 'pending' => false, 'registry_created' => $created];
    }

    private function validUuid(?string $uuid): void
    {
        $this->need($uuid !== null && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
    }

    private function unused(?string $uuid): void
    {
        $this->validUuid($uuid);
        $this->need(! DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first(), 'gated_void_operation_conflict');
    }

    private function entry(int $id, string $type): Transaction
    {
        return Transaction::query()->where('reference_type', Payment::class)->where('reference_id', $id)->where('type', $type)->lockForUpdate()->sole();
    }

    private function registered(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')->where('source_type', $leg['source_type'])
            ->where('source_id', $leg['source_id'])->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash), 'gated_void_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date, 'gated_void_prerequisite_unregistered');
        $this->registry->verifyPeriod('water', (int) $op->period_id);
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
