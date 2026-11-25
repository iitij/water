<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\AuditEvent;
use App\Support\TreasuryMoney;
use App\Models\Transaction;
use App\Support\AccountMoney;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Internal read-only verification of a modern, atomically cleared bank
 * collection. Not a writer, adoption path, balance certificate or close proof.
 * Caller must hold a scope-first transaction; legacy writers must be fenced
 * before use in production.
 */
final class RegisteredBankCollectionProof
{
    public function __construct(
        private FinancialLedgerEvidence $ledger,
        private TreasuryCollectionEvidence $collection,
        private TreasuryOpeningFundingEvidence $opening,
        private FinancialPostingRegistry $registry,
    ) {}

    public function verify(int $paymentId): array
    {
        $this->need(DB::transactionLevel() > 0, 'bank_pair_requires_outer_transaction');
        $scope = DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first();
        $this->need($scope !== null && $scope->mode === 'ACTIVE' && $scope->currency === 'YER'
            && $scope->timezone === 'Asia/Aden' && (string) $scope->calendar_version === '1',
            'bank_pair_scope_mismatch');
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'bank_pair_issuance_lock_missing');
        $payment = Payment::query()->lockForUpdate()->find($paymentId);
        $this->need($payment !== null && ! $payment->isVoided() && ! $payment->hasPendingVoidRequest()
            && in_array($payment->payment_method, ['check', 'bank_transfer'], true)
            && $payment->cash_session_id === null && is_string($payment->sync_uuid),
            'bank_pair_receipt_unsupported');
        $minor = AccountMoney::stored($payment);
        $this->need($minor > 0, 'bank_pair_receipt_unsupported');

        // Modern claim/review flow is required. A historical noncash receipt
        // lacking the originating claim is not silently made certified.
        $claims = DB::table('noncash_collection_claims')->where('payment_id', $paymentId)->lockForUpdate()->get();
        $reviews = DB::table('late_receipt_reviews')->where('payment_id', $paymentId)->lockForUpdate()->get();
        $this->need($claims->count() + $reviews->count() === 1, 'bank_pair_origin_unproved');
        if ($claims->isNotEmpty()) {
            $claim = $claims->first();
            $this->need($claim->status === 'cleared' && $claim->sync_uuid === $payment->sync_uuid,
                'bank_pair_origin_unproved');
        } else {
            $review = $reviews->first();
            $this->need($review->status === 'approved' && $review->settlement_method === 'verified_non_cash'
                && $review->bank_clearance_reference !== null && $review->sync_uuid === $payment->sync_uuid,
                'bank_pair_origin_unproved');
        }
        $document = DB::table('treasury_bank_collections')->where('payment_id', $paymentId)->lockForUpdate()->first();
        $this->need($document !== null, 'bank_pair_collection_missing');
        $postings = DB::table('treasury_postings')->where('treasury_bank_collection_id', $document->id)
            ->lockForUpdate()->get();
        $entries = Transaction::withTrashed()->where('reference_type', Payment::class)
            ->where('reference_id', $paymentId)->where('type', 'payment')->lockForUpdate()->get();
        $this->need($postings->count() === 1 && $entries->count() === 1 && ! $entries->first()->trashed(),
            'bank_pair_source_identity');
        $posting = $postings->first();
        $date = $payment->payment_date->toDateString();
        $this->need($posting->kind === 'bank_collection' && $posting->effective_on === $date
            && $document->cleared_on === $date, 'bank_pair_date_mismatch');

        $subscriberLeg = $this->ledger->leg((int) $entries->first()->id);
        $bankLeg = $this->collection->leg((int) $posting->id);
        $this->need($subscriberLeg['amount_minor'] === $minor && $bankLeg['amount_minor'] === $minor,
            'bank_pair_amount_mismatch');
        $subscriberOp = $this->registered($subscriberLeg, $date);
        $bankOp = $this->registered($bankLeg, $date);
        $this->need($subscriberOp->operation_uuid === $payment->sync_uuid
            && $bankOp->operation_uuid === $payment->sync_uuid, 'bank_pair_operation_mismatch');
        $events = DB::table('financial_posting_events')->where('operation_uuid', $payment->sync_uuid)
            ->lockForUpdate()->get();
        $this->need($events->count() === 2, 'bank_pair_unexpected_effects');

        $account = DB::table('treasury_accounts')->where('id', $posting->treasury_account_id)->lockForUpdate()->first();
        $this->need($account !== null && $account->channel === 'bank', 'bank_pair_account_mismatch');
        $openingLeg = $this->opening->opening((int) $account->id, $account->opening_on);
        $openingOp = $this->registered($openingLeg, $account->opening_on);
        foreach (array_unique([(int) $bankOp->period_id, (int) $openingOp->period_id]) as $periodId) {
            $this->registry->verifyPeriod('water', $periodId);
        }
        // The registered hashes prove the source rows, not the separate
        // mandatory audit records. Require both independent evidence trails.
        $line = DB::table('bank_statement_lines')->where('id', $document->bank_statement_line_id)->lockForUpdate()->first();
        $this->need($line !== null, 'bank_pair_audit_source_missing');
        $statement = DB::table('bank_statements')->where('id', $line->bank_statement_id)->lockForUpdate()->first();
        $match = DB::table('bank_statement_matches')->where('bank_statement_line_id', $line->id)->lockForUpdate()->first();
        $this->need($statement !== null && $match !== null, 'bank_pair_audit_source_missing');
        $lineCount = DB::table('bank_statement_lines')->where('bank_statement_id', $statement->id)
            ->orderBy('id')->lockForUpdate()->get(['id'])->count();
        $import = $this->audit('bank_statement.imported', 'bank_statement', (int) $statement->id);
        $this->need((int) $import->actor_user_id === (int) $statement->imported_by, 'bank_pair_import_audit_mismatch');
        $this->matches($import, [
            'treasury_account_id' => (int) $account->id, 'reference' => $statement->reference,
            'from_date' => $statement->from_date, 'to_date' => $statement->to_date,
            'opening_minor' => TreasuryMoney::signed($statement->opening_minor),
            'closing_minor' => TreasuryMoney::signed($statement->closing_minor),
            'evidence' => $statement->evidence, 'imported_by' => (int) $statement->imported_by,
            'content_hash' => $statement->content_hash, 'line_count' => $lineCount,
        ], 'bank_pair_import_audit_mismatch');
        $confirmation = $this->audit('treasury.bank_collection_confirmed', 'treasury_bank_collection', (int) $document->id);
        $this->need((int) $confirmation->actor_user_id === (int) $document->approved_by, 'bank_pair_confirmation_audit_mismatch');
        $this->matches($confirmation, [
            'treasury_account_id' => (int) $account->id, 'bank_statement_line_id' => (int) $line->id,
            'payment_id' => $paymentId, 'subscriber_id' => (int) $payment->subscriber_id,
            'receipt_number' => $payment->receipt_number, 'payment_reference' => $payment->reference_number,
            'payment_method' => $payment->payment_method, 'amount_minor' => $minor, 'cleared_on' => $date,
            'reference' => $document->reference, 'evidence' => $document->evidence,
            'approved_by' => (int) $document->approved_by, 'posting_id' => (int) $posting->id,
            'match_id' => (int) $match->id, 'subscriber_posting_created' => false,
        ], 'bank_pair_confirmation_audit_mismatch');
        if ($claims->isNotEmpty()) {
            $submission = $this->audit('noncash_claim.submitted', 'noncash_collection_claim', (int) $claim->id);
            $this->need((int) $submission->actor_user_id === (int) $claim->submitted_by, 'bank_pair_submission_audit_mismatch');
            $this->matches($submission, [
                'sync_uuid' => $claim->sync_uuid, 'subscriber_id' => (int) $claim->subscriber_id,
                'invoice_id' => $claim->invoice_id === null ? null : (int) $claim->invoice_id,
                'submitted_by' => (int) $claim->submitted_by, 'amount_minor' => $minor,
                'payment_method' => $claim->payment_method, 'instrument_reference' => $claim->instrument_reference,
                'instrument_on' => $claim->instrument_on, 'notes' => $claim->notes,
            ], 'bank_pair_submission_audit_mismatch');
            $decision = $this->audit('noncash_claim.cleared', 'noncash_collection_claim', (int) $claim->id);
            $this->need((int) $decision->actor_user_id === (int) $claim->decided_by, 'bank_pair_decision_audit_mismatch');
            $this->matches($decision, [
                'status' => 'cleared', 'payment_id' => $paymentId, 'decided_by' => (int) $claim->decided_by,
                'decision_evidence' => $claim->decision_evidence, 'bank_collection_id' => (int) $document->id,
            ], 'bank_pair_decision_audit_mismatch');
        } else {
            $submission = $this->audit('late_receipt.submitted', 'late_receipt_review', (int) $review->id);
            $this->need((int) $submission->actor_user_id === (int) $review->collector_id, 'bank_pair_submission_audit_mismatch');
            $this->matches($submission, [
                'sync_uuid' => $review->sync_uuid, 'cash_session_id' => (int) $review->cash_session_id,
                'collector_id' => (int) $review->collector_id, 'subscriber_id' => (int) $review->subscriber_id,
                'invoice_id' => $review->invoice_id === null ? null : (int) $review->invoice_id,
                'submitted_meter_id' => $review->submitted_meter_id === null ? null : (int) $review->submitted_meter_id,
                'amount' => \App\Support\SignedMinor::decimal($minor), 'payment_date' => $review->payment_date,
                'payment_method' => $review->payment_method, 'reference_number' => $review->reference_number,
                'reason' => $review->reason, 'notes' => $review->notes,
            ], 'bank_pair_submission_audit_mismatch');
            $decision = $this->audit('late_receipt.approved', 'late_receipt_review', (int) $review->id);
            $this->need((int) $decision->actor_user_id === (int) $review->decided_by, 'bank_pair_decision_audit_mismatch');
            $this->matches($decision, [
                'status' => 'approved', 'payment_id' => $paymentId, 'decided_by' => (int) $review->decided_by,
                'settlement_method' => 'verified_non_cash', 'evidence_reference' => $review->evidence_reference,
                'bank_clearance_reference' => $review->bank_clearance_reference, 'evidence' => $review->evidence,
                'new_cash_movement' => false, 'bank_collection_id' => (int) $document->id,
            ], 'bank_pair_decision_audit_mismatch');
        }

        return [
            'operation_uuid' => $payment->sync_uuid, 'payment_id' => $paymentId,
            'transaction_id' => (int) $subscriberLeg['source_id'], 'posting_id' => (int) $bankLeg['source_id'],
            'amount_minor' => $minor, 'effective_on' => $date,
            'pair_verified' => true, 'source_coverage_verified' => false, 'certified_balance' => false,
        ];
    }

    private function audit(string $event, string $type, int $id): AuditEvent
    {
        $rows = AuditEvent::query()->where('event', $event)->where('entity_type', $type)
            ->where('entity_id', (string) $id)->lockForUpdate()->get();
        $this->need($rows->count() === 1, 'bank_pair_audit_missing_or_duplicate');
        return $rows->first();
    }

    private function matches(AuditEvent $audit, array $expected, string $reason): void
    {
        $actual = $audit->after ?? [];
        foreach ($expected as $key => $value) {
            $this->need(array_key_exists($key, $actual) && $actual[$key] === $value, $reason);
        }
    }

    private function registered(array $leg, string $date): object
    {
        $event = DB::table('financial_posting_events')->where('scope_key', 'water')
            ->where('source_type', $leg['source_type'])->where('source_id', $leg['source_id'])
            ->where('leg', $leg['leg'])->lockForUpdate()->first();
        $this->need($event !== null && (string) $event->amount_minor === (string) $leg['amount_minor']
            && $event->currency === $leg['currency'] && hash_equals($leg['source_hash'], $event->source_hash),
            'bank_pair_unregistered_effect');
        $op = DB::table('financial_posting_operations')->where('operation_uuid', $event->operation_uuid)
            ->lockForUpdate()->first();
        $this->need($op !== null && $op->scope_key === 'water' && $op->effective_on === $date,
            'bank_pair_unregistered_effect');
        return $op;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
