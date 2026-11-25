<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
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

/** Inactive ordinary noncash claim clearance candidate. Records actual bank
 * clearance, not an instruction to transfer money. No legacy adoption.
 */
final class GatedNonCashClaimWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private PaymentService $payments,
        private TreasuryRegisterService $treasury,
        private TreasuryOpeningFundingEvidence $opening,
        private FinancialLedgerEvidence $ledger,
        private TreasuryCollectionEvidence $collection,
        private FinancialPostingRegistry $registry,
    ) {}

    public function clear(User $actor, int $claimId, int $statementId, int $lineId, string $bookedOn,
        string $reference, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        return $this->gate->withinOpenPeriod('water', $bookedOn, $now,
            function (array $context) use ($actor, $claimId, $statementId, $lineId, $reference, $evidence, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_claim_issuance_lock_missing');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
                $candidate = DB::table('noncash_collection_claims')->find($claimId);
                $this->need($candidate !== null, 'gated_claim_missing');
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
                $claim = DB::table('noncash_collection_claims')->where('id', $claimId)->lockForUpdate()->first();
                $this->need($claim !== null && (int) $claim->subscriber_id === (int) $subscriber->id
                    && $claim->sync_uuid === $uuid && in_array($claim->status, ['pending', 'cleared'], true)
                    && in_array($claim->payment_method, ['check', 'bank_transfer'], true),
                    'gated_claim_identity');
                abort_if((int) $claim->submitted_by === (int) $actor->id, 403);
                $minor = \App\Support\MoneyBoundary::readMinor($claim->amount_minor, true, \App\Support\DecimalDomain::D12);
                $this->need($minor > 0, 'gated_claim_amount');
                $submission = $this->audit('noncash_claim.submitted', 'noncash_collection_claim', $claimId);
                $this->need((int) $submission->actor_user_id === (int) $claim->submitted_by, 'gated_claim_submission_mismatch');
                foreach (['sync_uuid', 'subscriber_id', 'invoice_id', 'submitted_by', 'amount_minor',
                    'payment_method', 'instrument_reference', 'instrument_on', 'notes'] as $field) {
                    $this->need(array_key_exists($field, $submission->after ?? [])
                        && (string) $submission->after[$field] === (string) $claim->$field,
                        'gated_claim_submission_mismatch');
                }

                $statement = DB::table('bank_statements')->where('id', $statementId)->lockForUpdate()->first();
                $this->need($statement !== null, 'gated_claim_statement_missing');
                abort_if((int) $statement->imported_by === (int) $actor->id, 403);
                $account = DB::table('treasury_accounts')->where('id', $statement->treasury_account_id)->lockForUpdate()->first();
                $this->need($account !== null && $account->channel === 'bank', 'gated_claim_bank_account');
                $lines = DB::table('bank_statement_lines')->where('bank_statement_id', $statementId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $line = $lines->get($lineId);
                $this->need($line !== null && (int) $line->treasury_account_id === (int) $account->id
                    && $line->booked_on === $context['effective_on'] && $line->booked_on >= $claim->instrument_on
                    && $line->booked_on >= $account->opening_on, 'gated_claim_booking_date');
                $this->need(\App\Support\TreasuryMoney::signed($line->amount_minor) === $minor, 'gated_claim_amount');
                $import = $this->audit('bank_statement.imported', 'bank_statement', $statementId);
                $this->need((int) $import->actor_user_id === (int) $statement->imported_by
                    && ($import->after['content_hash'] ?? null) === $statement->content_hash
                    && ($import->after['line_count'] ?? null) === $lines->count(), 'gated_claim_import_mismatch');
                $opening = $this->opening->opening((int) $account->id, $account->opening_on);
                $periods = [(int) $this->registered($opening, $account->opening_on)->period_id];
                if ($claim->invoice_id !== null) {
                    $invoice = Invoice::query()->lockForUpdate()->findOrFail($claim->invoice_id);
                    $this->need((int) $invoice->subscriber_id === (int) $subscriber->id && $invoice->status !== 'draft',
                        'gated_claim_invoice_identity');
                    $invoiceEntry = Transaction::query()->where('reference_type', Invoice::class)
                        ->where('reference_id', $invoice->id)->where('type', 'invoice')->lockForUpdate()->sole();
                    $periods[] = (int) $this->registered($this->ledger->leg((int) $invoiceEntry->id),
                        $invoice->bill_date->toDateString())->period_id;
                    $this->need($claim->status === 'cleared' || $invoice->status !== 'cancelled', 'gated_claim_invoice_identity');
                }
                foreach (array_unique($periods) as $periodId) $this->registry->verifyPeriod('water', $periodId);

                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $replay = $claim->status === 'cleared';
                if ($replay) {
                    $this->need($operation !== null && (int) $claim->payment_id > 0, 'gated_claim_operation_conflict');
                    $doc = DB::table('treasury_bank_collections')->where('payment_id', $claim->payment_id)->lockForUpdate()->sole();
                    $posting = DB::table('treasury_postings')->where('treasury_bank_collection_id', $doc->id)->lockForUpdate()->sole();
                    foreach ([$this->ledger->leg((int) $this->entry((int) $claim->payment_id)->id),
                        $this->collection->leg((int) $posting->id)] as $leg) {
                        $this->need($this->registered($leg, $context['effective_on'])->operation_uuid === $uuid,
                            'gated_claim_operation_conflict');
                    }
                } else {
                    $this->need($operation === null && $claim->payment_id === null, 'gated_claim_operation_conflict');
                }
                $balances = app(TransactionService::class);
                $before = $balances->accountBalanceMinor($subscriber);
                $result = $this->payments->clearNonCashClaim($actor, $claimId, $statementId, $lineId, $reference, $evidence);
                $payment = Payment::query()->lockForUpdate()->findOrFail($result['payment_id']);
                $posting = $result['bank']['posting'];
                $doc = $result['bank']['collection'];
                $entry = $this->entry((int) $payment->id);
                $ledger = $this->ledger->leg((int) $entry->id);
                $bank = $this->collection->leg((int) $posting['id']);
                $this->need($result['claim']['status'] === 'cleared' && (int) $result['claim']['decided_by'] === (int) $actor->id
                    && (int) $result['claim']['payment_id'] === (int) $payment->id
                    && $payment->sync_uuid === $uuid && $payment->cash_session_id === null
                    && (int) $payment->subscriber_id === (int) $subscriber->id
                    && (int) $payment->user_id === (int) $claim->submitted_by
                    && (int) $payment->invoice_id === (int) $claim->invoice_id
                    && $payment->payment_method === $claim->payment_method
                    && $payment->reference_number === $claim->instrument_reference
                    && $payment->payment_date->toDateString() === $context['effective_on']
                    && $posting['effective_on'] === $context['effective_on']
                    && (int) $posting['treasury_account_id'] === (int) $account->id
                    && (int) $doc['bank_statement_line_id'] === $lineId
                    && AccountMoney::stored($payment) === $minor
                    && $ledger['amount_minor'] === $minor && $bank['amount_minor'] === $minor
                    && $balances->accountBalanceMinor($subscriber) === SignedMinor::add($before, $replay ? 0 : $minor),
                    'gated_claim_effect_mismatch');
                $decision = $this->audit('noncash_claim.cleared', 'noncash_collection_claim', $claimId);
                $this->need((int) $decision->actor_user_id === (int) $actor->id
                    && ($decision->after['status'] ?? null) === 'cleared'
                    && (int) ($decision->after['payment_id'] ?? 0) === (int) $payment->id
                    && (int) ($decision->after['bank_collection_id'] ?? 0) === (int) $doc['id']
                    && ($decision->after['decision_evidence'] ?? null) === trim($evidence), 'gated_claim_decision_mismatch');
                return $result + ['transaction_id' => (int) $entry->id,
                    'registry_created' => $this->registry->record($context, $uuid, [$ledger, $bank], $now)];
            });
    }

    private function entry(int $id): Transaction
    {
        return Transaction::query()->where('reference_type', Payment::class)->where('reference_id', $id)
            ->where('type', 'payment')->lockForUpdate()->sole();
    }

    private function audit(string $event, string $type, int $id): AuditEvent
    {
        return AuditEvent::query()->where('event', $event)->where('entity_type', $type)
            ->where('entity_id', (string) $id)->lockForUpdate()->sole();
    }

    private function registered(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_claim_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date,
            'gated_claim_prerequisite_unregistered');
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
