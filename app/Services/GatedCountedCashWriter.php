<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AccountMoney;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\SignedMinor;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Inactive allocation of cash already included in an approved physical count.
 * No new treasury movement. Initial scope: zero opening, ordinary registered
 * cash receipts, no legacy allocations or corrections.
 */
final class GatedCountedCashWriter
{
    public function __construct(
        private FinancialWriteGate $gate,
        private LateReceiptReviewService $reviews,
        private FinancialLedgerEvidence $ledger,
        private FinancialPostingRegistry $registry,
        private AuditLogger $audit,
    ) {}

    public function allocate(User $actor, int $reviewId, string $effectiveOn,
        string $reference, string $evidence, string $uuid, DateTimeImmutable $now): array
    {
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        return $this->gate->withinOpenPeriod('water', $effectiveOn, $now,
            function (array $context) use ($actor, $reviewId, $reference, $evidence, $uuid, $now): array {
                $this->need(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid) === 1, 'posting_uuid');
                $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
                    'gated_counted_issuance_lock_missing');
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                abort_unless($this->reviews->canDecide($actor), 403);
                $candidate = DB::table('late_receipt_reviews')->find($reviewId);
                $this->need($candidate !== null, 'gated_counted_review_missing');
                $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
                $review = DB::table('late_receipt_reviews')->where('id', $reviewId)->lockForUpdate()->first();
                $this->need($review !== null && (int) $review->subscriber_id === (int) $subscriber->id
                    && $review->sync_uuid === $uuid && $review->payment_method === 'cash'
                    && in_array($review->status, ['pending', 'approved'], true), 'gated_counted_review_identity');
                $this->need($review->payment_date === $context['effective_on'], 'gated_counted_effective_date');
                abort_if((int) $review->collector_id === (int) $actor->id, 403);
                $minor = $this->submission($review);
                $session = CashSession::query()->lockForUpdate()->findOrFail($review->cash_session_id);
                $driver = $session->getConnection()->getDriverName();
                $expected = Cash::stored($session->getRawOriginal('expected_amount'), $driver, nullable: true);
                $counted = Cash::stored($session->getRawOriginal('counted_amount'), $driver, nullable: true);
                $variance = Cash::stored($session->getRawOriginal('variance_amount'), $driver, signed: true, nullable: true);
                $this->need($session->isApproved() && $session->settlement_basis === 'physical_cash'
                    && (int) $session->user_id === (int) $review->collector_id
                    && (int) $session->approved_by > 0 && (int) $session->approved_by !== (int) $session->user_id
                    && $session->closed_at !== null && $session->approved_at !== null
                    && $session->closed_at <= $session->approved_at
                    && $review->payment_date >= $session->session_date->toDateString()
                    && $review->payment_date <= $session->closed_at->toDateString()
                    && Cash::consistent($counted, $expected, $variance), 'gated_counted_session_unverified');
                $this->need($variance > 0, 'gated_counted_no_surplus');
                $this->need(Cash::stored($session->getRawOriginal('opening_amount'), $driver) === 0
                    && ! DB::table('cash_session_fundings')->where('cash_session_id', $session->id)->exists(),
                    'gated_counted_funded_till_unsupported');
                $approval = $this->event('cash_session.approved', CashSession::class, (int) $session->id);
                $this->need((int) $approval->actor_user_id === (int) $session->approved_by
                    && ($approval->after['state'] ?? null) === 'approved'
                    && ($approval->after['expected_amount'] ?? null) === $session->expected_amount
                    && ($approval->after['counted_amount'] ?? null) === $session->counted_amount
                    && ($approval->after['variance_amount'] ?? null) === $session->variance_amount,
                    'gated_counted_approval_mismatch');
                $sessionBytes = $session->getRawOriginal();
                $receipts = Payment::withTrashed()->where('cash_session_id', $session->id)->orderBy('id')->lockForUpdate()->get();
                $receiptBytes = $receipts->map(fn ($p) => $p->getRawOriginal())->all();
                $sum = 0;
                $periods = [];
                foreach ($receipts as $payment) {
                    $this->need(! $payment->trashed() && ! $payment->isVoided() && ! $payment->hasPendingVoidRequest()
                        && $payment->payment_method === 'cash' && (int) $payment->user_id === (int) $session->user_id
                        && $payment->payment_date->toDateString() === $session->session_date->toDateString()
                        && is_string($payment->sync_uuid), 'gated_counted_till_receipt_unsupported');
                    $leg = $this->ledger->leg((int) $this->entry((int) $payment->id)->id);
                    $op = $this->registered($leg, $payment->payment_date->toDateString());
                    $this->need($op->operation_uuid === $payment->sync_uuid, 'gated_counted_till_receipt_unsupported');
                    $periods[] = (int) $op->period_id;
                    $sum = SignedMinor::add($sum, $leg['amount_minor']);
                }
                $this->need($sum === $expected, 'gated_counted_expected_mismatch');

                // Verify the complete prior allocation sequence using the
                // durable decision proof order, not the submission ID order.
                $prior = [];
                $approved = DB::table('late_receipt_reviews')->where('cash_session_id', $session->id)
                    ->where('status', 'approved')->orderBy('id')->lockForUpdate()->get();
                foreach ($approved as $row) {
                    $this->need($row->settlement_method === 'already_counted', 'gated_counted_other_settlement_unsupported');
                    $leg = $this->allocationLeg($row, $session);
                    $op = $this->registered($leg, $row->payment_date);
                    $this->need($op->operation_uuid === $row->sync_uuid, 'gated_counted_prior_unregistered');
                    $periods[] = (int) $op->period_id;
                    $proof = $this->event('financial.counted_cash_allocated', 'late_receipt_review', (int) $row->id);
                    $prior[] = ['row' => $row, 'leg' => $leg, 'proof' => $proof];
                }
                usort($prior, fn ($a, $b) => $a['proof']->id <=> $b['proof']->id);
                $allocated = 0;
                foreach ($prior as $item) {
                    $this->checkProof($item['proof'], $item['row'], $item['leg'], $session, (int) $approval->id,
                        $variance, $allocated);
                    $allocated = SignedMinor::add($allocated, $item['leg']['amount_minor']);
                    $this->need($allocated <= $variance, 'gated_counted_capacity_exceeded');
                }
                foreach (array_unique($periods) as $periodId) $this->registry->verifyPeriod('water', $periodId);
                $snapshot = $this->reviews->countedCashAllocation($session, true);
                $this->need($snapshot['eligible'] && $snapshot['capacity_minor'] === $variance
                    && $snapshot['allocated_minor'] === $allocated, 'gated_counted_capacity_mismatch');
                $replay = $review->status === 'approved';
                $operation = DB::table('financial_posting_operations')->where('operation_uuid', $uuid)->lockForUpdate()->first();
                $this->need($replay ? $operation !== null : ($operation === null && $review->payment_id === null),
                    'gated_counted_operation_conflict');
                $this->need($replay || $minor <= SignedMinor::subtract($variance, $allocated), 'gated_counted_capacity_exceeded');

                if ($review->invoice_id !== null) {
                    $invoice = Invoice::query()->lockForUpdate()->findOrFail($review->invoice_id);
                    $this->need((int) $invoice->subscriber_id === (int) $subscriber->id && $invoice->status !== 'draft'
                        && ($replay || $invoice->status !== 'cancelled'), 'gated_counted_invoice_identity');
                    $entry = Transaction::query()->where('reference_type', Invoice::class)->where('reference_id', $invoice->id)
                        ->where('type', 'invoice')->lockForUpdate()->sole();
                    $op = $this->registered($this->ledger->leg((int) $entry->id), $invoice->bill_date->toDateString());
                    $this->registry->verifyPeriod('water', (int) $op->period_id);
                }
                $balance = app(TransactionService::class);
                $before = $balance->accountBalanceMinor($subscriber);
                $result = $this->reviews->decide($reviewId, $actor, ['approve' => true, 'settlement_method' => 'already_counted',
                    'evidence_reference' => $reference, 'evidence' => $evidence]);
                $row = (object) $result;
                $leg = $this->allocationLeg($row, $session);
                $this->need($leg['amount_minor'] === $minor && (int) $row->decided_by === (int) $actor->id
                    && $row->evidence_reference === $reference && $row->evidence === $evidence
                    && $balance->accountBalanceMinor($subscriber) === SignedMinor::add($before, $replay ? 0 : $minor)
                    && $session->fresh()->getRawOriginal() === $sessionBytes
                    && Payment::withTrashed()->where('cash_session_id', $session->id)->orderBy('id')->lockForUpdate()->get()
                        ->map(fn ($p) => $p->getRawOriginal())->all() === $receiptBytes,
                    'gated_counted_effect_mismatch');
                $after = SignedMinor::add($allocated, $replay ? 0 : $minor);
                $snapshot = $this->reviews->countedCashAllocation($session->fresh(), true);
                $this->need($snapshot['eligible'] && $snapshot['capacity_minor'] === $variance
                    && $snapshot['allocated_minor'] === $after
                    && $snapshot['available_minor'] === SignedMinor::subtract($variance, $after), 'gated_counted_capacity_mismatch');
                if (! $replay) {
                    $proof = $this->audit->recordRequired('financial.counted_cash_allocated', [
                        'entity_type' => 'late_receipt_review', 'entity_id' => $reviewId, 'actor_user_id' => $actor->id,
                        'after' => $this->proof($row, $leg, $session, (int) $approval->id, $variance, $allocated),
                    ]);
                    $this->checkProof($proof, $row, $leg, $session, (int) $approval->id, $variance, $allocated);
                }
                return ['review' => $result, 'transaction_id' => (int) $leg['source_id'],
                    'allocation' => $snapshot, 'registry_created' => $this->registry->record($context, $uuid, [$leg], $now)];
            });
    }

    private function submission(object $row): int
    {
        $audit = $this->event('late_receipt.submitted', 'late_receipt_review', (int) $row->id);
        $minor = Cash::positiveStored($row->amount, DB::connection()->getDriverName(), DecimalDomain::D12);
        $this->need((int) $audit->actor_user_id === (int) $row->collector_id
            && Cash::input($audit->after['amount'] ?? '', 'amount', DecimalDomain::D12, 1) === $minor,
            'gated_counted_submission_mismatch');
        foreach (['sync_uuid', 'cash_session_id', 'subscriber_id', 'invoice_id', 'collector_id', 'submitted_meter_id',
            'payment_method', 'reference_number', 'payment_date', 'reason', 'notes'] as $field) {
            $this->need(array_key_exists($field, $audit->after ?? []) && (string) $audit->after[$field] === (string) $row->$field,
                'gated_counted_submission_mismatch');
        }
        return $minor;
    }

    private function allocationLeg(object $row, CashSession $session): array
    {
        $minor = $this->submission($row);
        $this->need($row->status === 'approved' && $row->settlement_method === 'already_counted'
            && $row->payment_method === 'cash' && (int) $row->cash_session_id === (int) $session->id
            && (int) $row->collector_id === (int) $session->user_id
            && (int) $row->decided_by > 0 && (int) $row->decided_by !== (int) $row->collector_id
            && $row->payment_date >= $session->session_date->toDateString()
            && $row->payment_date <= $session->closed_at->toDateString()
            && $row->bank_clearance_reference === null && (int) $row->payment_id > 0
            && ! DB::table('treasury_late_cash_receipts')->where('late_receipt_review_id', $row->id)->exists(),
            'gated_counted_allocation_identity');
        $payment = Payment::query()->lockForUpdate()->findOrFail($row->payment_id);
        $this->need(! $payment->isVoided() && ! $payment->hasPendingVoidRequest()
            && $payment->cash_session_id === null && $payment->payment_method === 'cash'
            && $payment->sync_uuid === $row->sync_uuid && (int) $payment->subscriber_id === (int) $row->subscriber_id
            && (int) $payment->user_id === (int) $row->collector_id && (int) $payment->invoice_id === (int) $row->invoice_id
            && $payment->payment_date->toDateString() === $row->payment_date
            && $payment->reference_number === $row->reference_number && AccountMoney::stored($payment) === $minor,
            'gated_counted_allocation_identity');
        foreach (['payment_refunds', 'payment_account_transfers', 'treasury_bank_collections'] as $table) {
            $this->need(! DB::table($table)->where('payment_id', $payment->id)->exists(), 'gated_counted_allocation_identity');
        }
        $this->need(! Transaction::withTrashed()->where('reference_type', Payment::class)->where('reference_id', $payment->id)
            ->where('type', 'payment_reversal')->exists(), 'gated_counted_allocation_identity');
        $decision = $this->event('late_receipt.approved', 'late_receipt_review', (int) $row->id);
        $this->need((int) $decision->actor_user_id === (int) $row->decided_by
            && ($decision->after['counted_cash_allocation'] ?? null) === true
            && ($decision->after['new_cash_movement'] ?? null) === false
            && ($decision->after['settlement_method'] ?? null) === 'already_counted'
            && (int) ($decision->after['payment_id'] ?? 0) === (int) $payment->id
            && ($decision->after['evidence'] ?? null) === $row->evidence
            && ($decision->after['evidence_reference'] ?? null) === $row->evidence_reference,
            'gated_counted_decision_mismatch');
        $leg = $this->ledger->leg((int) $this->entry((int) $payment->id)->id);
        $this->need($leg['amount_minor'] === $minor, 'gated_counted_effect_mismatch');
        return $leg;
    }

    private function proof(object $row, array $leg, CashSession $session, int $approvalId, int $capacity, int $before): array
    {
        return ['operation_uuid' => $row->sync_uuid, 'session_id' => (int) $session->id, 'approval_audit_id' => $approvalId,
            'payment_id' => (int) $row->payment_id, 'transaction_id' => (int) $leg['source_id'], 'ledger_hash' => $leg['source_hash'],
            'capacity_minor' => $capacity, 'allocated_before_minor' => $before,
            'allocated_after_minor' => SignedMinor::add($before, $leg['amount_minor']),
            'amount_minor' => $leg['amount_minor'], 'effective_on' => $row->payment_date,
            'reference' => $row->evidence_reference, 'evidence' => $row->evidence, 'new_cash_movement' => false];
    }

    private function checkProof(AuditEvent $proof, object $row, array $leg, CashSession $session,
        int $approvalId, int $capacity, int $before): void
    {
        $this->need((int) $proof->actor_user_id === (int) $row->decided_by, 'gated_counted_proof_mismatch');
        foreach ($this->proof($row, $leg, $session, $approvalId, $capacity, $before) as $key => $value) {
            $this->need(array_key_exists($key, $proof->after ?? []) && $proof->after[$key] === $value, 'gated_counted_proof_mismatch');
        }
    }

    private function event(string $event, string $type, int $id): AuditEvent
    {
        return AuditEvent::query()->where('event', $event)->where('entity_type', $type)
            ->where('entity_id', (string) $id)->lockForUpdate()->sole();
    }

    private function entry(int $id): Transaction
    {
        return Transaction::query()->where('reference_type', Payment::class)->where('reference_id', $id)
            ->where('type', 'payment')->lockForUpdate()->sole();
    }

    private function registered(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'gated_counted_prerequisite_unregistered');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date,
            'gated_counted_prerequisite_unregistered');
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
