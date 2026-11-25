<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\PaymentAccountTransfer;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Inactive candidate: correct the account of an entire registered ordinary
 * cash receipt without changing the physical receipt or moving treasury cash.
 * No partial transfer, legacy adoption, noncash or exceptional late receipts.
 */
final class GatedPaymentAccountTransferWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private PaymentAccountTransferService $transfers,
        private FinancialLedgerEvidence $ledger,
        private FinancialPostingRegistry $registry,
    ) {}

    public function transfer(User $actor, int $paymentId, int $destinationId, string $reason,
        string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        $reason = trim($reason);
        $evidence = trim($evidence);
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $paymentId, $destinationId, $reason, $evidence, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_account_transfer_issuance_lock_missing');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                abort_unless($actor->hasRole('super_admin') ||
                    ($actor->can('cash_sessions.approve') && $actor->can('payments.void.approve')), 403);
                $candidate = Payment::query()->findOrFail($paymentId);
                $fromId = (int) $candidate->subscriber_id;
                $this->need($fromId !== $destinationId && $destinationId > 0, 'gated_account_transfer_destination');
                $accounts = Subscriber::query()->whereIn('id', [$fromId, $destinationId])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $this->need($accounts->count() === 2, 'gated_account_transfer_destination');
                $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
                $this->need((int) $payment->subscriber_id === $fromId
                    && $payment->payment_method === 'cash' && is_string($payment->sync_uuid)
                    && ! $payment->isVoided() && ! $payment->hasPendingVoidRequest()
                    && ! DB::table('late_receipt_reviews')->where('payment_id', $paymentId)->exists()
                    && ! DB::table('noncash_collection_claims')->where('payment_id', $paymentId)->exists()
                    && ! DB::table('treasury_bank_collections')->where('payment_id', $paymentId)->exists()
                    && ! DB::table('closed_cash_corrections')->where('duplicate_of_id', $paymentId)->exists()
                    && ! DB::table('payment_refunds')->where('payment_id', $paymentId)->exists(),
                    'gated_account_transfer_receipt_unsupported');
                abort_if((int) $payment->user_id === (int) $actor->id, 403);
                $session = $payment->cash_session_id
                    ? CashSession::query()->lockForUpdate()->findOrFail($payment->cash_session_id) : null;
                if ($session !== null) {
                    abort_if((int) $session->user_id === (int) $actor->id, 403);
                    $this->need($session->isApproved(), 'gated_account_transfer_till_unapproved');
                }
                $original = $this->entry(Payment::class, $paymentId, 'payment');
                $originalLeg = $this->ledger->leg((int) $original->id);
                $originalOp = $this->registeredOperation($originalLeg, $payment->payment_date->toDateString());
                $this->need($originalOp->operation_uuid === $payment->sync_uuid
                    && $originalLeg['amount_minor'] === AccountMoney::stored($payment), 'gated_account_transfer_original_identity');
                $this->registry->verifyPeriod('water', (int) $originalOp->period_id);

                $existing = PaymentAccountTransfer::query()->where('payment_id', $paymentId)->lockForUpdate()->first();
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)
                    ->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->need($operation !== null && (int) $existing->from_subscriber_id === $fromId
                        && (int) $existing->to_subscriber_id === $destinationId
                        && (int) $existing->approved_by === (int) $actor->id
                        && $existing->reason === $reason && $existing->evidence === $evidence,
                        'gated_account_transfer_operation_conflict');
                    $out = $this->entry(Payment::class, $paymentId, 'payment_reversal');
                    $in = $this->entry(PaymentAccountTransfer::class, (int) $existing->id, 'payment_transfer_in');
                    foreach ([$out, $in] as $entry) {
                        $registered = $this->registeredOperation($this->ledger->leg((int) $entry->id), $context['effective_on']);
                        $this->need($registered->operation_uuid === $uuid, 'gated_account_transfer_operation_conflict');
                    }
                } else {
                    $this->need($operation === null, 'gated_account_transfer_operation_conflict');
                }

                $originalReceipt = $payment->getRawOriginal();
                $originalTill = $session?->getRawOriginal();
                $balances = app(TransactionService::class);
                $beforeFrom = $balances->accountBalanceMinor($accounts->get($fromId));
                $beforeTo = $balances->accountBalanceMinor($accounts->get($destinationId));
                $transfer = $this->transfers->transfer($payment, $destinationId, $actor, $reason, $evidence);
                $out = $this->entry(Payment::class, $paymentId, 'payment_reversal');
                $in = $this->entry(PaymentAccountTransfer::class, (int) $transfer->id, 'payment_transfer_in');
                $outLeg = $this->ledger->leg((int) $out->id);
                $inLeg = $this->ledger->leg((int) $in->id);
                $minor = $originalLeg['amount_minor'];
                $this->need((int) $transfer->payment_id === $paymentId
                    && (int) $transfer->from_subscriber_id === $fromId
                    && (int) $transfer->to_subscriber_id === $destinationId
                    && (int) $transfer->approved_by === (int) $actor->id
                    && $transfer->reason === $reason && $transfer->evidence === $evidence
                    && $transfer->created_at?->toDateString() === $context['effective_on']
                    && AccountMoney::stored($transfer) === $minor
                    && $outLeg['amount_minor'] === -$minor && $inLeg['amount_minor'] === $minor
                    && (int) $out->subscriber_id === $fromId && (int) $in->subscriber_id === $destinationId
                    && $this->ledger->leg((int) $original->id) === $originalLeg,
                    'gated_account_transfer_effect_mismatch');
                $this->need($payment->fresh()->getRawOriginal() === $originalReceipt
                    && ($session === null || $session->fresh()->getRawOriginal() === $originalTill),
                    'gated_account_transfer_custody_changed');
                $delta = $existing === null ? $minor : 0;
                $this->need($balances->accountBalanceMinor($accounts->get($fromId)) === SignedMinor::subtract($beforeFrom, $delta)
                    && $balances->accountBalanceMinor($accounts->get($destinationId)) === SignedMinor::add($beforeTo, $delta),
                    'gated_account_transfer_balance_mismatch');
                $audit = AuditEvent::query()->where('event', 'payment.account_transferred')
                    ->where('entity_type', PaymentAccountTransfer::class)->where('entity_id', (string) $transfer->id)
                    ->lockForUpdate()->sole();
                $this->need((int) $audit->actor_user_id === (int) $actor->id
                    && ($audit->after['cash_movement'] ?? null) === false
                    && ($audit->after['reason'] ?? null) === $reason && ($audit->after['evidence'] ?? null) === $evidence
                    && (int) ($audit->after['out_transaction_id'] ?? 0) === (int) $out->id
                    && (int) ($audit->after['in_transaction_id'] ?? 0) === (int) $in->id
                    && is_int($audit->before['from_minor'] ?? null) && is_int($audit->before['to_minor'] ?? null)
                    && ($audit->after['from_minor'] ?? null) === SignedMinor::subtract($audit->before['from_minor'], $minor)
                    && ($audit->after['to_minor'] ?? null) === SignedMinor::add($audit->before['to_minor'], $minor),
                    'gated_account_transfer_audit_mismatch');
                return ['transfer' => $transfer, 'out_transaction_id' => (int) $out->id,
                    'in_transaction_id' => (int) $in->id,
                    'registry_created' => $this->registry->record($context, $uuid, [$outLeg, $inLeg], $now)];
            });
    }

    private function registeredOperation(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_account_transfer_prerequisite_unregistered');
        $operation = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)
            ->lockForUpdate()->first();
        $this->need($operation !== null && $operation->scope_key === 'water' && $operation->effective_on === $date,
            'gated_account_transfer_prerequisite_unregistered');
        return $operation;
    }

    private function entry(string $class, int $id, string $type): Transaction
    {
        $entries = Transaction::withTrashed()->where('reference_type', $class)
            ->where('reference_id', $id)->where('type', $type)->lockForUpdate()->get();
        $this->need($entries->count() === 1 && ! $entries->first()->trashed(), 'gated_account_transfer_ledger_identity');
        return $entries->first();
    }

    private function need(bool $condition, string $reason): void
    {
        if (! $condition) throw new DomainException($reason);
    }
}
