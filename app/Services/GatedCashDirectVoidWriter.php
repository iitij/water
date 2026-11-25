<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use App\Support\FinancialAmount;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Inactive ordinary cash receipt direct-void candidate.
 * A positive explicitly configured threshold is mandatory in this adapter:
 * zero, missing or malformed thresholds require a separate policy decision.
 * No pending approval may be bypassed. No physical disbursement is performed.
 */
final class GatedCashDirectVoidWriter
{
    private const SOURCE = 'gated_direct_void';

    public function __construct(
        private FinancialWriteGate $gate,
        private PaymentService $payments,
        private FinancialLedgerEvidence $ledger,
        private FinancialPostingRegistry $registry,
        private AuditLogger $audit,
    ) {}

    public function void(User $actor, int $paymentId, string $reason, string $uuid, DateTimeImmutable $now): array
    {
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        $reason = trim($reason);

        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $paymentId, $reason, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(mb_strlen($reason) >= 10 && mb_strlen($reason) <= 1000, 'gated_direct_void_reason');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_direct_void_issuance_lock_missing');

                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                // Narrow authorization until all API and Filament permission paths are integrated.
                abort_unless($actor->hasRole('super_admin'), 403);
                $candidate = Payment::query()->findOrFail($paymentId);
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
                $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
                $this->need((int) $payment->subscriber_id === (int) $subscriber->id
                    && $payment->payment_method === 'cash' && is_string($payment->sync_uuid)
                    && ! DB::table('late_receipt_reviews')->where('payment_id', $paymentId)->exists()
                    && ! DB::table('noncash_collection_claims')->where('payment_id', $paymentId)->exists(),
                    'gated_direct_void_receipt_unsupported');

                $original = $this->entry($paymentId, 'payment');
                $originalLeg = $this->ledger->leg((int) $original->id);
                $originalOperation = $this->registeredOperation($originalLeg, $payment->payment_date->toDateString());
                $this->need($originalOperation->operation_uuid === $payment->sync_uuid
                    && $originalLeg['amount_minor'] === AccountMoney::stored($payment), 'gated_direct_void_original_identity');
                $this->registry->verifyPeriod('water', (int) $originalOperation->period_id);
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)
                    ->lockForUpdate()->first();

                if ($payment->isVoided()) {
                    $this->need($operation !== null && $payment->voided_via === self::SOURCE
                        && (int) $payment->voided_by === (int) $actor->id && $payment->void_reason === $reason,
                        'gated_direct_void_operation_conflict');
                    $reversal = $this->entry($paymentId, 'payment_reversal');
                    $registered = $this->registeredOperation($this->ledger->leg((int) $reversal->id), $context['effective_on']);
                    $this->need($registered->operation_uuid === $uuid, 'gated_direct_void_operation_conflict');
                } else {
                    $this->need($operation === null, 'gated_direct_void_operation_conflict');
                    $this->need(! $payment->hasPendingVoidRequest(), 'gated_direct_void_pending_approval');
                    // The locked current configuration, not a caller amount or stale read,
                    // determines eligibility. Also use the original request service; if it
                    // returns a pending request, abort the entire candidate transaction.
                    $setting = DB::table('settings')->where('key', 'void_approval_threshold')->lockForUpdate()->first();
                    $this->need($setting !== null, 'gated_direct_void_threshold_required');
                    $threshold = FinancialAmount::validated($setting->value, 'void_approval_threshold')->minor;
                    $this->need($threshold > 0, 'gated_direct_void_threshold_required');
                    $this->need($originalLeg['amount_minor'] < $threshold, 'gated_direct_void_requires_approval');
                    $result = $this->payments->requestVoid($payment, $reason, (int) $actor->id, self::SOURCE);
                    $this->need($result['pending'] === false && $result['payment']->isVoided(),
                        'gated_direct_void_requires_approval');
                    $payment = $result['payment'];
                    $reversal = $this->entry($paymentId, 'payment_reversal');
                    $this->audit->recordRequired('financial.direct_void_registered', [
                        'entity_type' => Payment::class, 'entity_id' => $paymentId, 'actor_user_id' => $actor->id,
                        'after' => ['operation_uuid' => $uuid, 'threshold_minor' => $threshold,
                            'receipt_minor' => $originalLeg['amount_minor'], 'reason' => $reason,
                            'reversal_id' => (int) $reversal->id],
                    ]);
                }

                $this->need($payment->isVoided() && $payment->voided_via === self::SOURCE
                    && (int) $payment->voided_by === (int) $actor->id && $payment->void_reason === $reason
                    && $payment->voided_at?->toDateString() === $context['effective_on'],
                    'gated_direct_void_effect_mismatch');
                $proof = AuditEvent::query()->where('event', 'financial.direct_void_registered')
                    ->where('entity_type', Payment::class)->where('entity_id', (string) $paymentId)
                    ->lockForUpdate()->sole();
                $this->need((int) $proof->actor_user_id === (int) $actor->id
                    && ($proof->after['operation_uuid'] ?? null) === $uuid
                    && ($proof->after['reason'] ?? null) === $reason
                    && ($proof->after['receipt_minor'] ?? null) === $originalLeg['amount_minor']
                    && ($proof->after['reversal_id'] ?? null) === (int) $reversal->id
                    && is_int($proof->after['threshold_minor'] ?? null)
                    && $proof->after['threshold_minor'] > $originalLeg['amount_minor'], 'gated_direct_void_audit_mismatch');
                $leg = $this->ledger->leg((int) $reversal->id);
                $this->need($leg['amount_minor'] === -$originalLeg['amount_minor']
                    && $this->ledger->leg((int) $original->id) === $originalLeg, 'gated_direct_void_effect_mismatch');
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
            'gated_direct_void_prerequisite_unregistered');
        $operation = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)
            ->lockForUpdate()->first();
        $this->need($operation !== null && $operation->scope_key === 'water' && $operation->effective_on === $date,
            'gated_direct_void_prerequisite_unregistered');
        return $operation;
    }

    private function entry(int $paymentId, string $type): Transaction
    {
        $entries = Transaction::withTrashed()->where('reference_type', Payment::class)
            ->where('reference_id', $paymentId)->where('type', $type)->lockForUpdate()->get();
        $this->need($entries->count() === 1 && ! $entries->first()->trashed(), 'gated_direct_void_ledger_identity');
        return $entries->first();
    }

    private function need(bool $condition, string $reason): void
    {
        if (! $condition) throw new DomainException($reason);
    }
}
