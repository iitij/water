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
        $reference = mb_strtoupper(trim($reference));
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $paymentId, $accountId, $amount, $reference, $method, $date, $reason, $evidence, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null, 'gated_refund_issuance_lock');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
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
                }
                $balance = app(TransactionService::class);
                $before = $balance->accountBalanceMinor($subscriber);
                $refund = $this->refunds->record($payment, $actor, $amount, $reference, $method, $date, $reason, $evidence);
                $posting = $this->treasury->post($actor, $accountId, 'payment_refund', (int) $refund->id);
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
                return ['refund' => $refund, 'posting' => $posting, 'transaction_id' => (int) $entry->id, 'registry_created' => $created];
            });
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

    private function registered(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_refund_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date, 'gated_refund_prerequisite_unregistered');
        $this->registry->verifyPeriod('water', (int) $op->period_id);
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
