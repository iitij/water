<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive dual-source writer. Only a common verified effective date is supported.
 * No adoption of prior unregistered effects or public route activation.
 */
final class GatedCollectionWriter
{
    private ?array $mutationIdentity = null;

    public function __construct(
        private FinancialWriteGate $gate,
        private PaymentService $payments,
        private LateReceiptReviewService $late,
        private FinancialLedgerEvidence $ledger,
        private TreasuryCollectionEvidence $collection,
        private TreasuryOpeningFundingEvidence $opening,
        private FinancialPostingRegistry $registry,
    ) {}

    public function clearClaim(User $actor, int $id, int $statementId, int $lineId,
        string $effectiveOn, string $reference, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        return $this->write($actor, 'claim', $id, $statementId, $lineId, $effectiveOn, $uuid, $now,
            fn (User $locked) => $this->payments->clearNonCashClaim($locked, $id, $statementId, $lineId, $reference, $evidence, $this));
    }

    public function clearLateBank(User $actor, int $id, int $statementId, int $lineId,
        string $effectiveOn, string $reviewReference, string $bankReference, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        return $this->write($actor, 'late_bank', $id, $statementId, $lineId, $effectiveOn, $uuid, $now,
            fn (User $locked) => $this->payments->clearLateNonCash($locked, $id, $statementId, $lineId, $reviewReference, $bankReference, $evidence, $this));
    }

    public function receiveLateCash(User $actor, int $id, int $accountId, string $receivedOn,
        string $reference, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        return $this->write($actor, 'late_cash', $id, $accountId, null, $receivedOn, $uuid, $now,
            fn (User $locked) => $this->late->receiveCash($id, $locked, $accountId, $receivedOn, $reference, $evidence, $this));
    }

    private function write(User $actor, string $kind, int $id, int $destination, ?int $lineId,
        string $date, string $uuid, DateTimeImmutable $now, callable $action): array
    {
        return $this->gate->withinOpenPeriod('water', $date, $now,
            function (array $context) use ($actor, $kind, $id, $destination, $lineId, $uuid, $now, $action): array {
                app(SubscriberFinancialWriteProtection::class)->requireActive();
                app(TreasuryFinancialWriteProtection::class)->requireActive();
                try {
                    $active = app(CollectionEvidenceAuditProtection::class)->active()
                        && app(CollectionEvidencePeriodProtection::class)->active()
                        && app(FinancialEvidenceDestinationProtection::class)->active()
                        && ($kind === 'late_cash' || (app(BankEvidencePeriodProtection::class)->active()
                            && app(BankRangeDestinationProtection::class)->active()));
                } catch (\LogicException $error) {
                    throw new DomainException('collection_financial_write_protection_missing', 0, $error);
                }
                $this->need($active, 'collection_financial_write_protection_missing');
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                // Scope, all prerequisite periods, issuance, then business rows.
                DB::table('financial_periods')->where('scope_key', 'water')->orderBy('id')->lockForUpdate()->get();
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_collection_issuance_lock');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                abort_if($actor->isDisabled(), 403);
                $actor->setRelation('roles', $actor->roles()->lockForUpdate()->get());
                app(TreasuryRegisterService::class)->authorize($actor);
                $table = $kind === 'claim' ? 'noncash_collection_claims' : 'late_receipt_reviews';
                $candidate = DB::table($table)->find($id);
                $this->need($candidate !== null, 'gated_collection_claim_missing');
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
                $claim = DB::table($table)->where('id', $id)->lockForUpdate()->first();
                $approved = $kind === 'claim' ? 'cleared' : 'approved';
                $this->need($claim !== null && (int) $claim->subscriber_id === (int) $subscriber->id
                    && $claim->sync_uuid === $uuid && in_array($claim->status, ['pending', $approved], true),
                    'gated_collection_claim_identity');
                $replay = $claim->status === $approved;
                if ($kind === 'late_cash') {
                    $this->need($claim->payment_method === 'cash' && $claim->payment_date === $context['effective_on'],
                        'gated_collection_effective_date');
                    $accountId = $destination;
                } else {
                    $statement = DB::table('bank_statements')->where('id', $destination)->lockForUpdate()->first();
                    $line = DB::table('bank_statement_lines')->where('bank_statement_id', $destination)
                        ->where('id', $lineId)->lockForUpdate()->first();
                    $this->need($statement !== null && $line !== null && $line->booked_on === $context['effective_on']
                        && (int) $line->treasury_account_id === (int) $statement->treasury_account_id
                        && in_array($claim->payment_method, ['check', 'bank_transfer'], true), 'gated_collection_effective_date');
                    app(BankReconciliationService::class)->assertEvidenceRangeOpen($statement->from_date, $statement->to_date);
                    $accountId = (int) $statement->treasury_account_id;
                }
                $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
                $this->need($account !== null && $account->channel === ($kind === 'late_cash' ? 'cash' : 'bank'),
                    'gated_collection_destination');
                $this->registered($this->opening->opening($accountId, $account->opening_on), $account->opening_on);
                if ($claim->invoice_id !== null) {
                    $invoice = Invoice::query()->lockForUpdate()->findOrFail($claim->invoice_id);
                    $this->need((int) $invoice->subscriber_id === (int) $subscriber->id
                        && $invoice->status !== 'draft' && ($replay || $invoice->status !== 'cancelled'),
                        'gated_collection_invoice_identity');
                    $entry = Transaction::query()->where('reference_type', Invoice::class)->where('reference_id', $invoice->id)
                        ->where('type', 'invoice')->lockForUpdate()->sole();
                    $this->registered($this->ledger->leg((int) $entry->id), $invoice->bill_date->toDateString());
                }

                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $existing = Payment::withTrashed()->where('sync_uuid', $uuid)->lockForUpdate()->first();
                $this->need($replay ? ($operation !== null && $existing !== null && ! $existing->trashed()
                    && (int) $claim->payment_id === (int) $existing->id)
                    : ($operation === null && $existing === null && $claim->payment_id === null),
                    'gated_collection_operation_conflict');
                $balance = app(TransactionService::class);
                $before = $balance->accountBalanceMinor($subscriber);
                $result = $this->mutate($kind, $id, $uuid, fn () => $action($actor));
                $decision = $kind === 'claim' ? $result['claim'] : $result['review'];
                $posting = $kind === 'late_cash' ? $result['posting'] : $result['bank']['posting'];
                $payment = Payment::query()->lockForUpdate()->findOrFail($decision['payment_id']);
                $minor = AccountMoney::stored($payment);
                $this->need($minor > 0 && $payment->sync_uuid === $uuid
                    && (int) $payment->subscriber_id === (int) $subscriber->id && $payment->cash_session_id === null
                    && $payment->payment_date->toDateString() === $context['effective_on']
                    && $decision['status'] === $approved && (int) $decision['decided_by'] === (int) $actor->id
                    && (int) $posting['treasury_account_id'] === $accountId
                    && $posting['effective_on'] === $context['effective_on']
                    && $posting['kind'] === ($kind === 'late_cash' ? 'late_cash_receipt' : 'bank_collection')
                    && $balance->accountBalanceMinor($subscriber) === SignedMinor::add($before, $replay ? 0 : $minor),
                    'gated_collection_effect_mismatch');
                $entry = Transaction::query()->where('reference_type', Payment::class)->where('reference_id', $payment->id)
                    ->where('type', 'payment')->lockForUpdate()->sole();
                $legs = [$this->ledger->leg((int) $entry->id), $this->collection->leg((int) $posting['id'])];
                foreach ($legs as $leg) {
                    $this->need($leg['amount_minor'] === $minor, 'gated_collection_amount_mismatch');
                    if ($replay) {
                        $op = $this->registered($leg, $context['effective_on']);
                        $this->need($op->operation_uuid === $uuid, 'gated_collection_operation_conflict');
                    }
                }
                $created = $this->registry->record($context, $uuid, $legs, $now);
                $this->need($created === ! $replay, 'gated_collection_operation_conflict');
                return $result + ['transaction_id' => (int) $entry->id, 'registry_created' => $created];
            });
    }

    /** The exact verified claim is authorized only inside the scope-first write. */
    public function allowsMutation(string $kind, int $id, string $uuid): bool
    {
        return $this->mutationIdentity === [$kind, $id, $uuid] && DB::transactionLevel() > 0;
    }

    private function mutate(string $kind, int $id, string $uuid, callable $action): array
    {
        $this->need($this->mutationIdentity === null && DB::transactionLevel() > 0, 'gated_collection_mutation_scope');
        $this->mutationIdentity = [$kind, $id, $uuid];
        try {
            return $action();
        } finally {
            $this->mutationIdentity = null;
        }
    }

    private function registered(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_collection_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date,
            'gated_collection_prerequisite_unregistered');
        $this->registry->verifyPeriod('water', (int) $op->period_id);
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
