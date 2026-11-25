<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Inactive candidate for recording independently evidenced cash refunds only.
 * The actual cash disbursement is external to this operation. No money is sent.
 * Requires a registered ordinary cash receipt and registered cash opening.
 * Scope-first locking is not safe alongside unfenced legacy writers.
 */
final class GatedCashRefundWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private PaymentRefundService $refunds,
        private TreasuryRegisterService $treasury,
        private FinancialLedgerEvidence $ledger,
        private TreasuryCustodyEvidence $custody,
        private TreasuryOpeningFundingEvidence $opening,
        private FinancialPostingRegistry $registry,
    ) {}

    public function record(User $actor, int $paymentId, int $accountId, string $amount,
        string $reference, string $reason, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        $date = $now->setTimezone(new DateTimeZone('Asia/Aden'))->format('Y-m-d');
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $paymentId, $accountId, $amount, $reference, $reason, $evidence, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_refund_issuance_lock_missing');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
                $candidate = Payment::query()->findOrFail($paymentId);
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
                $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
                $this->need((int) $payment->subscriber_id === (int) $subscriber->id
                    && $payment->payment_method === 'cash' && is_string($payment->sync_uuid)
                    && ! DB::table('late_receipt_reviews')->where('payment_id', $paymentId)->exists()
                    && ! DB::table('noncash_collection_claims')->where('payment_id', $paymentId)->exists(),
                    'gated_refund_receipt_unsupported');
                $original = $this->entry(Payment::class, $paymentId, 'payment');
                $originalLeg = $this->ledger->leg((int) $original->id);
                $originalOp = $this->registeredOperation($originalLeg, $payment->payment_date->toDateString());
                $this->need($originalOp->operation_uuid === $payment->sync_uuid, 'gated_refund_original_identity');

                $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
                $this->need($account !== null && $account->channel === 'cash', 'gated_refund_cash_account');
                $openingLeg = $this->opening->opening($accountId, $account->opening_on);
                $openingOp = $this->registeredOperation($openingLeg, $account->opening_on);
                foreach (array_unique([(int) $originalOp->period_id, (int) $openingOp->period_id]) as $periodId) {
                    $this->registry->verifyPeriod('water', $periodId);
                }

                $existing = PaymentRefund::query()->where('disbursement_reference', mb_strtoupper(trim($reference)))
                    ->lockForUpdate()->first();
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)
                    ->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->need($operation !== null && (int) $existing->payment_id === $paymentId
                        && (int) $existing->recorded_by === (int) $actor->id
                        && $existing->reason === trim($reason) && $existing->evidence === trim($evidence),
                        'gated_refund_operation_conflict');
                    $posting = $this->posting((int) $existing->id);
                    $entry = $this->entry(PaymentRefund::class, (int) $existing->id, 'payment_refund');
                    $this->need((int) $posting->treasury_account_id === $accountId
                        && (int) $posting->posted_by === (int) $actor->id,
                        'gated_refund_operation_conflict');
                    foreach ([$this->ledger->leg((int) $entry->id), $this->custody->leg((int) $posting->id)] as $leg) {
                        $registered = $this->registeredOperation($leg, $context['effective_on']);
                        $this->need($registered->operation_uuid === $uuid, 'gated_refund_operation_conflict');
                    }
                } else {
                    $this->need($operation === null, 'gated_refund_operation_conflict');
                }

                // Original service checks positive amount, independent actor, approved
                // till if present, outstanding debt, refundable credit and prior refunds.
                $refund = $this->refunds->record($payment, $actor, $amount, $reference,
                    'treasury_cash', $context['effective_on'], $reason, $evidence);
                $posting = $this->treasury->post($actor, $accountId, 'payment_refund', (int) $refund->id);
                $entry = $this->entry(PaymentRefund::class, (int) $refund->id, 'payment_refund');
                $ledger = $this->ledger->leg((int) $entry->id);
                $cash = $this->custody->leg((int) $posting['id']);
                $this->need($posting['effective_on'] === $context['effective_on']
                    && $refund->disbursed_on->toDateString() === $context['effective_on']
                    && $ledger['amount_minor'] === -AccountMoney::stored($refund)
                    && $cash['amount_minor'] === $ledger['amount_minor'], 'gated_refund_effect_mismatch');
                return ['refund' => $refund, 'transaction_id' => (int) $entry->id, 'posting_id' => (int) $posting['id'],
                    'registry_created' => $this->registry->record($context, $uuid, [$ledger, $cash], $now)];
            });
    }

    private function registeredOperation(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_refund_prerequisite_unregistered');
        $operation = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)
            ->lockForUpdate()->first();
        $this->need($operation !== null && $operation->scope_key === 'water' && $operation->effective_on === $date,
            'gated_refund_prerequisite_unregistered');
        return $operation;
    }

    private function entry(string $class, int $id, string $type): Transaction
    {
        return Transaction::query()->where('reference_type', $class)->where('reference_id', $id)
            ->where('type', $type)->lockForUpdate()->sole();
    }

    private function posting(int $id): object
    {
        return DB::table('treasury_postings')->where('payment_refund_id', $id)->lockForUpdate()->sole();
    }

    private function need(bool $condition, string $reason): void
    {
        if (! $condition) throw new DomainException($reason);
    }
}
