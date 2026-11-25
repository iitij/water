<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashSession;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
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

/** Inactive late noncash clearance candidate. Records actual bank
 * clearance, not an instruction to transfer money. No legacy adoption.
 */
final class GatedLateNonCashWriter
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

    public function clear(User $actor, int $reviewId, int $statementId, int $lineId, string $bookedOn,
        string $reviewReference, string $reference, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        return $this->gate->withinOpenPeriod('water', $bookedOn, $now,
            function (array $context) use ($actor, $reviewId, $statementId, $lineId, $reviewReference, $reference, $evidence, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_late_bank_issuance_lock_missing');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
                $candidate = DB::table('late_receipt_reviews')->find($reviewId);
                $this->need($candidate !== null, 'gated_late_bank_missing');
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
                $review = DB::table('late_receipt_reviews')->where('id', $reviewId)->lockForUpdate()->first();
                $this->need($review !== null && (int) $review->subscriber_id === (int) $subscriber->id
                    && $review->sync_uuid === $uuid && in_array($review->status, ['pending', 'approved'], true)
                    && in_array($review->payment_method, ['check', 'bank_transfer'], true),
                    'gated_late_bank_identity');
                abort_if((int) $review->collector_id === (int) $actor->id, 403);
                $minor = Cash::positiveStored($review->amount, DB::connection()->getDriverName(), DecimalDomain::D12);
                $this->need($minor > 0, 'gated_late_bank_amount');
                $submission = $this->audit('late_receipt.submitted', 'late_receipt_review', $reviewId);
                $this->need((int) $submission->actor_user_id === (int) $review->collector_id, 'gated_late_bank_submission_mismatch');
                foreach (['sync_uuid', 'cash_session_id', 'subscriber_id', 'invoice_id', 'collector_id', 'submitted_meter_id',
                    'payment_method', 'reference_number', 'payment_date', 'reason', 'notes'] as $field) {
                    $this->need(array_key_exists($field, $submission->after ?? [])
                        && (string) $submission->after[$field] === (string) $review->$field,
                        'gated_late_bank_submission_mismatch');
                }

                $this->need(Cash::input($submission->after['amount'] ?? '', 'amount', DecimalDomain::D12, 1) === $minor,
                    'gated_late_bank_submission_mismatch');
                $session = CashSession::query()->lockForUpdate()->findOrFail($review->cash_session_id);
                $this->need($session->isApproved() && $session->closed_at !== null && $session->approved_at !== null
                    && (int) $session->user_id === (int) $review->collector_id
                    && (int) $session->approved_by > 0 && (int) $session->approved_by !== (int) $session->user_id
                    && $session->closed_at <= $session->approved_at
                    && $session->settlement_basis === 'physical_cash'
                    && $review->payment_date >= $session->session_date->toDateString()
                    && $review->payment_date <= $session->closed_at->toDateString(),
                    'gated_late_bank_session_unverified');
                $approval = $this->audit('cash_session.approved', CashSession::class, (int) $session->id);
                $this->need((int) $approval->actor_user_id === (int) $session->approved_by
                    && ($approval->after['state'] ?? null) === 'approved'
                    && ($approval->after['expected_amount'] ?? null) === $session->expected_amount
                    && ($approval->after['counted_amount'] ?? null) === $session->counted_amount
                    && ($approval->after['variance_amount'] ?? null) === $session->variance_amount,
                    'gated_late_bank_approval_mismatch');
                $sessionBytes = $session->getRawOriginal();
                $tillPayments = Payment::withTrashed()->where('cash_session_id', $session->id)
                    ->orderBy('id')->lockForUpdate()->get()->map(fn ($p) => $p->getRawOriginal())->all();

                $statement = DB::table('bank_statements')->where('id', $statementId)->lockForUpdate()->first();
                $this->need($statement !== null, 'gated_late_bank_statement_missing');
                abort_if((int) $statement->imported_by === (int) $actor->id, 403);
                $account = DB::table('treasury_accounts')->where('id', $statement->treasury_account_id)->lockForUpdate()->first();
                $this->need($account !== null && $account->channel === 'bank', 'gated_late_bank_bank_account');
                $lines = DB::table('bank_statement_lines')->where('bank_statement_id', $statementId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $line = $lines->get($lineId);
                $this->need($line !== null && (int) $line->treasury_account_id === (int) $account->id
                    && $line->booked_on === $context['effective_on'] && $line->booked_on >= $review->payment_date
                    && $line->booked_on >= $account->opening_on, 'gated_late_bank_booking_date');
                $this->need(\App\Support\TreasuryMoney::signed($line->amount_minor) === $minor, 'gated_late_bank_amount');
                $import = $this->audit('bank_statement.imported', 'bank_statement', $statementId);
                $this->need((int) $import->actor_user_id === (int) $statement->imported_by
                    && ($import->after['content_hash'] ?? null) === $statement->content_hash
                    && ($import->after['line_count'] ?? null) === $lines->count(), 'gated_late_bank_import_mismatch');
                $opening = $this->opening->opening((int) $account->id, $account->opening_on);
                $periods = [(int) $this->registered($opening, $account->opening_on)->period_id];
                if ($review->invoice_id !== null) {
                    $invoice = Invoice::query()->lockForUpdate()->findOrFail($review->invoice_id);
                    $this->need((int) $invoice->subscriber_id === (int) $subscriber->id && $invoice->status !== 'draft',
                        'gated_late_bank_invoice_identity');
                    $invoiceEntry = Transaction::query()->where('reference_type', Invoice::class)
                        ->where('reference_id', $invoice->id)->where('type', 'invoice')->lockForUpdate()->sole();
                    $periods[] = (int) $this->registered($this->ledger->leg((int) $invoiceEntry->id),
                        $invoice->bill_date->toDateString())->period_id;
                    $this->need($review->status === 'approved' || $invoice->status !== 'cancelled', 'gated_late_bank_invoice_identity');
                }
                foreach (array_unique($periods) as $periodId) $this->registry->verifyPeriod('water', $periodId);

                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $replay = $review->status === 'approved';
                $this->need(! $replay || $review->settlement_method === 'verified_non_cash', 'gated_late_bank_operation_conflict');
                if ($replay) {
                    $this->need($operation !== null && (int) $review->payment_id > 0, 'gated_late_bank_operation_conflict');
                    $doc = DB::table('treasury_bank_collections')->where('payment_id', $review->payment_id)->lockForUpdate()->sole();
                    $posting = DB::table('treasury_postings')->where('treasury_bank_collection_id', $doc->id)->lockForUpdate()->sole();
                    foreach ([$this->ledger->leg((int) $this->entry((int) $review->payment_id)->id),
                        $this->collection->leg((int) $posting->id)] as $leg) {
                        $this->need($this->registered($leg, $context['effective_on'])->operation_uuid === $uuid,
                            'gated_late_bank_operation_conflict');
                    }
                } else {
                    $this->need($operation === null && $review->payment_id === null, 'gated_late_bank_operation_conflict');
                }
                $balances = app(TransactionService::class);
                $before = $balances->accountBalanceMinor($subscriber);
                $result = $this->payments->clearLateNonCash($actor, $reviewId, $statementId, $lineId, $reviewReference, $reference, $evidence);
                $payment = Payment::query()->lockForUpdate()->findOrFail($result['review']['payment_id']);
                $posting = $result['bank']['posting'];
                $doc = $result['bank']['collection'];
                $entry = $this->entry((int) $payment->id);
                $ledger = $this->ledger->leg((int) $entry->id);
                $bank = $this->collection->leg((int) $posting['id']);
                $this->need($result['review']['status'] === 'approved' && $result['review']['settlement_method'] === 'verified_non_cash' && (int) $result['review']['decided_by'] === (int) $actor->id
                    && (int) $result['review']['payment_id'] === (int) $payment->id
                    && $payment->sync_uuid === $uuid && $payment->cash_session_id === null
                    && (int) $payment->subscriber_id === (int) $subscriber->id
                    && (int) $payment->user_id === (int) $review->collector_id
                    && (int) $payment->invoice_id === (int) $review->invoice_id
                    && $payment->payment_method === $review->payment_method
                    && $payment->reference_number === $review->reference_number
                    && $payment->payment_date->toDateString() === $context['effective_on']
                    && $posting['effective_on'] === $context['effective_on']
                    && (int) $posting['treasury_account_id'] === (int) $account->id
                    && (int) $doc['bank_statement_line_id'] === $lineId
                    && AccountMoney::stored($payment) === $minor
                    && $ledger['amount_minor'] === $minor && $bank['amount_minor'] === $minor
                    && $balances->accountBalanceMinor($subscriber) === SignedMinor::add($before, $replay ? 0 : $minor)
                    && $session->fresh()->getRawOriginal() === $sessionBytes
                    && Payment::withTrashed()->where('cash_session_id', $session->id)->orderBy('id')->lockForUpdate()->get()
                        ->map(fn ($p) => $p->getRawOriginal())->all() === $tillPayments,
                    'gated_late_bank_effect_mismatch');
                $decision = $this->audit('late_receipt.approved', 'late_receipt_review', $reviewId);
                $this->need((int) $decision->actor_user_id === (int) $actor->id
                    && ($decision->after['status'] ?? null) === 'approved'
                    && (int) ($decision->after['payment_id'] ?? 0) === (int) $payment->id
                    && (int) ($decision->after['bank_collection_id'] ?? 0) === (int) $doc['id']
                    && ($decision->after['evidence'] ?? null) === trim($evidence)
                    && ($decision->after['settlement_method'] ?? null) === 'verified_non_cash'
                    && ($decision->after['evidence_reference'] ?? null) === mb_strtoupper(trim($reviewReference))
                    && ($decision->after['bank_clearance_reference'] ?? null) === mb_strtoupper(trim($reference))
                    && ($decision->after['new_cash_movement'] ?? null) === false, 'gated_late_bank_decision_mismatch');
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
            'gated_late_bank_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date,
            'gated_late_bank_prerequisite_unregistered');
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
