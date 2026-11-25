<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Inactive candidate for approval of an ordinary cash receipt void request.
 * Does not authorize immediate voids, send cash, or correct a closed till.
 * Requires an independently verified registered original receipt and a pending
 * request. The original posting is retained; only the reversal is registered.
 */
final class GatedCashVoidApprovalWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private PaymentService $payments,
        private FinancialLedgerEvidence $ledger,
        private FinancialPostingRegistry $registry,
    ) {}

    public function approve(User $actor, int $paymentId, string $reason, ?string $notes,
        string $uuid, DateTimeImmutable $now): array
    {
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        $reason = trim($reason);
        $notes = $notes === null ? null : trim($notes);

        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $paymentId, $reason, $notes, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_void_issuance_lock_missing');

                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                abort_unless($actor->hasRole('super_admin') || $actor->can('payments.void.approve'), 403);
                $candidate = Payment::query()->findOrFail($paymentId);
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
                $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
                $this->need((int) $payment->subscriber_id === (int) $subscriber->id
                    && $payment->payment_method === 'cash' && is_string($payment->sync_uuid)
                    && ! DB::table('late_receipt_reviews')->where('payment_id', $paymentId)->exists()
                    && ! DB::table('noncash_collection_claims')->where('payment_id', $paymentId)->exists(),
                    'gated_void_receipt_unsupported');
                $this->need(mb_strlen($reason) >= 10 && mb_strlen($reason) <= 1000
                    && ($notes === null || mb_strlen($notes) <= 1000), 'gated_void_decision_invalid');

                $original = $this->entry($paymentId, 'payment');
                $originalLeg = $this->ledger->leg((int) $original->id);
                $originalOperation = $this->registeredOperation($originalLeg, $payment->payment_date->toDateString());
                $this->need($originalOperation->operation_uuid === $payment->sync_uuid
                    && $originalLeg['amount_minor'] === AccountMoney::stored($payment), 'gated_void_original_identity');
                $this->registry->verifyPeriod('water', (int) $originalOperation->period_id);

                $this->need((int) $payment->void_requested_by > 0
                    && $payment->void_requested_at !== null
                    && $payment->void_request_reason === $reason, 'gated_void_request_mismatch');
                abort_if((int) $payment->void_requested_by === (int) $actor->id
                    || (int) $payment->user_id === (int) $actor->id, 403);
                $requestAudit = AuditEvent::query()->where('event', 'payment.void_requested')
                    ->where('entity_type', Payment::class)->where('entity_id', (string) $paymentId)
                    ->orderByDesc('id')->lockForUpdate()->first();
                $this->need($requestAudit !== null
                    && (int) $requestAudit->actor_user_id === (int) $payment->void_requested_by
                    && ($requestAudit->after['reason'] ?? null) === $reason
                    && ($requestAudit->after['receipt_number'] ?? null) === $payment->receipt_number
                    && is_int($requestAudit->after['threshold_minor'] ?? null)
                    && $requestAudit->after['threshold_minor'] > 0
                    && $originalLeg['amount_minor'] >= $requestAudit->after['threshold_minor'],
                    'gated_void_request_evidence_mismatch');

                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)
                    ->lockForUpdate()->first();
                if ($payment->isVoided()) {
                    $this->need($operation !== null && $payment->voided_via === 'approval'
                        && (int) $payment->voided_by === (int) $actor->id
                        && (int) $payment->void_decided_by === (int) $actor->id
                        && $payment->void_reason === $reason && $payment->void_decision_notes === $notes,
                        'gated_void_operation_conflict');
                    $reversal = $this->entry($paymentId, 'payment_reversal');
                    $registered = $this->registeredOperation($this->ledger->leg((int) $reversal->id), $context['effective_on']);
                    $this->need($registered->operation_uuid === $uuid, 'gated_void_operation_conflict');
                } else {
                    $this->need($operation === null && $payment->hasPendingVoidRequest(), 'gated_void_operation_conflict');
                    $payment = $this->payments->decideVoidRequest($payment, true, $actor, $notes, 'gated_approval');
                    $reversal = $this->entry($paymentId, 'payment_reversal');
                }

                $this->need($payment->isVoided() && $payment->voided_via === 'approval'
                    && (int) $payment->voided_by === (int) $actor->id
                    && (int) $payment->void_decided_by === (int) $actor->id
                    && $payment->void_reason === $reason && $payment->void_decision_notes === $notes
                    && $payment->voided_at?->toDateString() === $context['effective_on']
                    && $payment->void_decided_at?->toDateString() === $context['effective_on'],
                    'gated_void_effect_mismatch');
                $decision = AuditEvent::query()->where('event', 'payment.void_approved')
                    ->where('entity_type', Payment::class)->where('entity_id', (string) $paymentId)
                    ->lockForUpdate()->sole();
                $this->need((int) $decision->actor_user_id === (int) $actor->id
                    && ($decision->after['reason'] ?? null) === $reason
                    && array_key_exists('notes', $decision->after ?? [])
                    && $decision->after['notes'] === $notes, 'gated_void_decision_evidence_mismatch');
                $leg = $this->ledger->leg((int) $reversal->id);
                $this->need($leg['amount_minor'] === -$originalLeg['amount_minor']
                    && $this->ledger->leg((int) $original->id) === $originalLeg, 'gated_void_effect_mismatch');
                return ['payment' => $payment, 'transaction_id' => (int) $reversal->id,
                    'registry_created' => $this->registry->record($context, $uuid, [$leg], $now)];
            });
    }

    private function registeredOperation(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_void_prerequisite_unregistered');
        $operation = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)
            ->lockForUpdate()->first();
        $this->need($operation !== null && $operation->scope_key === 'water' && $operation->effective_on === $date,
            'gated_void_prerequisite_unregistered');
        return $operation;
    }

    private function entry(int $paymentId, string $type): Transaction
    {
        $entries = Transaction::withTrashed()->where('reference_type', Payment::class)
            ->where('reference_id', $paymentId)->where('type', $type)->lockForUpdate()->get();
        $this->need($entries->count() === 1 && ! $entries->first()->trashed(), 'gated_void_ledger_identity');
        return $entries->first();
    }

    private function need(bool $condition, string $reason): void
    {
        if (! $condition) throw new DomainException($reason);
    }
}
