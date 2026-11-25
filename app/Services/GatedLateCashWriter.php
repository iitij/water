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

/** Inactive separate physical cash receipt candidate. Same effective date only.
 * Distinct subscriber and treasury dates need a reviewed multi-date contract.
 * Records evidence; does not initiate any movement. No legacy adoption.
 */
final class GatedLateCashWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private LateReceiptReviewService $reviews,
        private TreasuryRegisterService $treasury,
        private TreasuryOpeningFundingEvidence $opening,
        private FinancialLedgerEvidence $ledger,
        private TreasuryCollectionEvidence $collection,
        private FinancialPostingRegistry $registry,
    ) {}

    public function receive(User $actor, int $reviewId, int $accountId, string $receivedOn,
        string $reference, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        return $this->gate->withinOpenPeriod('water', $receivedOn, $now,
            function (array $context) use ($actor, $reviewId, $accountId, $reference, $evidence, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_late_cash_issuance_lock_missing');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->treasury->authorize($actor);
                $candidate = DB::table('late_receipt_reviews')->find($reviewId);
                $this->need($candidate !== null, 'gated_late_cash_missing');
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
                $review = DB::table('late_receipt_reviews')->where('id', $reviewId)->lockForUpdate()->first();
                $this->need($review !== null && (int) $review->subscriber_id === (int) $subscriber->id
                    && $review->sync_uuid === $uuid && in_array($review->status, ['pending', 'approved'], true)
                    && $review->payment_method === 'cash',
                    'gated_late_cash_identity');
                abort_if((int) $review->collector_id === (int) $actor->id, 403);
                $minor = Cash::positiveStored($review->amount, DB::connection()->getDriverName(), DecimalDomain::D12);
                $this->need($minor > 0, 'gated_late_cash_amount');
                $submission = $this->audit('late_receipt.submitted', 'late_receipt_review', $reviewId);
                $this->need((int) $submission->actor_user_id === (int) $review->collector_id, 'gated_late_cash_submission_mismatch');
                foreach (['sync_uuid', 'cash_session_id', 'subscriber_id', 'invoice_id', 'collector_id', 'submitted_meter_id',
                    'payment_method', 'reference_number', 'payment_date', 'reason', 'notes'] as $field) {
                    $this->need(array_key_exists($field, $submission->after ?? [])
                        && (string) $submission->after[$field] === (string) $review->$field,
                        'gated_late_cash_submission_mismatch');
                }

                $this->need(Cash::input($submission->after['amount'] ?? '', 'amount', DecimalDomain::D12, 1) === $minor,
                    'gated_late_cash_submission_mismatch');
                $session = CashSession::query()->lockForUpdate()->findOrFail($review->cash_session_id);
                $this->need($session->isApproved() && $session->closed_at !== null && $session->approved_at !== null
                    && (int) $session->user_id === (int) $review->collector_id
                    && (int) $session->approved_by > 0 && (int) $session->approved_by !== (int) $session->user_id
                    && $session->closed_at <= $session->approved_at
                    && $session->settlement_basis === 'physical_cash'
                    && $review->payment_date >= $session->session_date->toDateString()
                    && $review->payment_date <= $session->closed_at->toDateString(),
                    'gated_late_cash_session_unverified');
                $approval = $this->audit('cash_session.approved', CashSession::class, (int) $session->id);
                $this->need((int) $approval->actor_user_id === (int) $session->approved_by
                    && ($approval->after['state'] ?? null) === 'approved'
                    && ($approval->after['expected_amount'] ?? null) === $session->expected_amount
                    && ($approval->after['counted_amount'] ?? null) === $session->counted_amount
                    && ($approval->after['variance_amount'] ?? null) === $session->variance_amount,
                    'gated_late_cash_approval_mismatch');
                $sessionBytes = $session->getRawOriginal();
                $tillPayments = Payment::withTrashed()->where('cash_session_id', $session->id)
                    ->orderBy('id')->lockForUpdate()->get()->map(fn ($p) => $p->getRawOriginal())->all();

                // The current source service backdates the subscriber receipt to
                // the review's original payment date. One registry operation
                // cannot safely certify two different effective dates.
                $this->need($review->payment_date === $context['effective_on'],
                    'gated_late_cash_distinct_dates_unsupported');
                $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
                $this->need($account !== null && $account->channel === 'cash'
                    && $context['effective_on'] >= $account->opening_on, 'gated_late_cash_account');
                // A pending review cannot be separately received if a prior
                // decision or receipt already recorded the same movement.
                $prior = DB::table('treasury_late_cash_receipts')->where('late_receipt_review_id', $reviewId)
                    ->lockForUpdate()->first();
                $opening = $this->opening->opening((int) $account->id, $account->opening_on);
                $periods = [(int) $this->registered($opening, $account->opening_on)->period_id];
                if ($review->invoice_id !== null) {
                    $invoice = Invoice::query()->lockForUpdate()->findOrFail($review->invoice_id);
                    $this->need((int) $invoice->subscriber_id === (int) $subscriber->id && $invoice->status !== 'draft',
                        'gated_late_cash_invoice_identity');
                    $invoiceEntry = Transaction::query()->where('reference_type', Invoice::class)
                        ->where('reference_id', $invoice->id)->where('type', 'invoice')->lockForUpdate()->sole();
                    $periods[] = (int) $this->registered($this->ledger->leg((int) $invoiceEntry->id),
                        $invoice->bill_date->toDateString())->period_id;
                    $this->need($review->status === 'approved' || $invoice->status !== 'cancelled', 'gated_late_cash_invoice_identity');
                }
                foreach (array_unique($periods) as $periodId) $this->registry->verifyPeriod('water', $periodId);

                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $replay = $review->status === 'approved';
                $this->need(! $replay || $review->settlement_method === 'separate_handover', 'gated_late_cash_operation_conflict');
                if ($replay) {
                    $this->need($operation !== null && (int) $review->payment_id > 0 && $prior !== null, 'gated_late_cash_operation_conflict');
                    $doc = $prior;
                    $posting = DB::table('treasury_postings')->where('treasury_late_cash_receipt_id', $doc->id)->lockForUpdate()->sole();
                    foreach ([$this->ledger->leg((int) $this->entry((int) $review->payment_id)->id),
                        $this->collection->leg((int) $posting->id)] as $leg) {
                        $this->need($this->registered($leg, $context['effective_on'])->operation_uuid === $uuid,
                            'gated_late_cash_operation_conflict');
                    }
                } else {
                    $this->need($operation === null && $review->payment_id === null && $prior === null, 'gated_late_cash_operation_conflict');
                }
                $balances = app(TransactionService::class);
                $before = $balances->accountBalanceMinor($subscriber);
                $result = $this->reviews->receiveCash($reviewId, $actor, $accountId, $context['effective_on'], $reference, $evidence);
                $payment = Payment::query()->lockForUpdate()->findOrFail($result['review']['payment_id']);
                $posting = $result['posting'];
                $doc = $result['receipt'];
                $entry = $this->entry((int) $payment->id);
                $ledger = $this->ledger->leg((int) $entry->id);
                $bank = $this->collection->leg((int) $posting['id']);
                $this->need($result['review']['status'] === 'approved' && $result['review']['settlement_method'] === 'separate_handover' && (int) $result['review']['decided_by'] === (int) $actor->id
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
                    && (int) $doc['late_receipt_review_id'] === $reviewId
                    && (int) $doc['received_by'] === (int) $actor->id
                    && $doc['received_on'] === $context['effective_on']
                    && AccountMoney::stored($payment) === $minor
                    && $ledger['amount_minor'] === $minor && $bank['amount_minor'] === $minor
                    && $balances->accountBalanceMinor($subscriber) === SignedMinor::add($before, $replay ? 0 : $minor)
                    && $session->fresh()->getRawOriginal() === $sessionBytes
                    && Payment::withTrashed()->where('cash_session_id', $session->id)->orderBy('id')->lockForUpdate()->get()
                        ->map(fn ($p) => $p->getRawOriginal())->all() === $tillPayments,
                    'gated_late_cash_effect_mismatch');
                $decision = $this->audit('late_receipt.approved', 'late_receipt_review', $reviewId);
                $this->need((int) $decision->actor_user_id === (int) $actor->id
                    && ($decision->after['status'] ?? null) === 'approved'
                    && (int) ($decision->after['payment_id'] ?? 0) === (int) $payment->id
                    && ($decision->after['evidence'] ?? null) === trim($evidence)
                    && ($decision->after['settlement_method'] ?? null) === 'separate_handover'
                    && ($decision->after['evidence_reference'] ?? null) === mb_strtoupper(trim($reference))
                    && ($decision->after['new_cash_movement'] ?? null) === false, 'gated_late_cash_decision_mismatch');
                $receiptAudit = $this->audit('treasury.late_cash_received', 'treasury_late_cash_receipt', (int) $doc['id']);
                $this->need((int) $receiptAudit->actor_user_id === (int) $actor->id
                    && (int) ($receiptAudit->after['payment_id'] ?? 0) === (int) $payment->id
                    && (int) ($receiptAudit->after['posting_id'] ?? 0) === (int) $posting['id']
                    && ($receiptAudit->after['original_till_changed'] ?? null) === false
                    && ($receiptAudit->after['amount_minor'] ?? null) === $minor,
                    'gated_late_cash_receipt_audit_mismatch');
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
            'gated_late_cash_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date,
            'gated_late_cash_prerequisite_unregistered');
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
