<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\Transaction;
use App\Support\TreasuryMoney;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Evidence for actual bank collection and separately received late cash.
 * Scope-first transaction required; not a certified balance or complete coverage.
 * No production caller until the global lock order and writer fencing are proved.
 */
final class TreasuryCollectionEvidence
{
    public function leg(int $postingId): array
    {
        $this->need(DB::transactionLevel() > 0 && $postingId > 0, 'collection_evidence_requires_gate');
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'collection_evidence_issuance_lock');
        $posting = $this->row('treasury_postings', $postingId);
        $account = $this->row('treasury_accounts', (int) $posting->treasury_account_id);
        $minor = TreasuryMoney::document($posting->amount_minor, true);
        $this->need((int) $posting->posted_by > 0, 'collection_evidence_actor');
        if ($posting->kind === 'bank_collection') {
            $doc = $this->row('treasury_bank_collections', (int) $posting->treasury_bank_collection_id);
            $line = $this->row('bank_statement_lines', (int) $doc->bank_statement_line_id);
            $statement = $this->row('bank_statements', (int) $line->bank_statement_id);
            DB::table('bank_statement_lines')->where('bank_statement_id', $statement->id)
                ->orderBy('id')->lockForUpdate()->get();
            $match = DB::table('bank_statement_matches')->where('bank_statement_line_id', $line->id)
                ->lockForUpdate()->first();
            $payment = Payment::query()->lockForUpdate()->find($doc->payment_id);
            $this->need($payment !== null && $match !== null && $account->channel === 'bank'
                && app(BankReconciliationService::class)->checkCollectionPosting($posting), 'collection_evidence_bank_mismatch');
            $source = [
                'collection_id' => (string) $doc->id, 'statement_id' => (string) $statement->id,
                'statement_hash' => $statement->content_hash, 'line_id' => (string) $line->id,
                'bank_reference' => $line->bank_reference, 'match_id' => (string) $match->id,
                'imported_by' => (string) $statement->imported_by, 'matched_by' => (string) $match->matched_by,
                'payment_method' => $doc->payment_method, 'payment_reference' => $doc->payment_reference,
                'receipt_number' => $doc->receipt_number, 'evidence' => $doc->evidence,
            ];
            // The existing bank validator checks the decision linkage. Bind the
            // actual decision identity and original claim date into its proof.
            $claims = DB::table('noncash_collection_claims')->where('payment_id', $payment->id)
                ->orderBy('id')->lockForUpdate()->get();
            $reviews = DB::table('late_receipt_reviews')->where('payment_id', $payment->id)
                ->orderBy('id')->lockForUpdate()->get();
            $this->need($claims->count() + $reviews->count() <= 1, 'collection_evidence_decision_conflict');
            if ($claims->isNotEmpty()) {
                $claim = $claims->first();
                $source['claim'] = ['id' => (string) $claim->id, 'sync_uuid' => $claim->sync_uuid,
                    'submitted_by' => (string) $claim->submitted_by, 'decided_by' => (string) $claim->decided_by,
                    'instrument_on' => $claim->instrument_on, 'instrument_reference' => $claim->instrument_reference,
                    'decided_at' => (string) $claim->decided_at, 'evidence' => $claim->decision_evidence];
            }
            if ($reviews->isNotEmpty()) {
                $review = $reviews->first();
                $source['late_review'] = ['id' => (string) $review->id, 'sync_uuid' => $review->sync_uuid,
                    'session_id' => (string) $review->cash_session_id, 'collector_id' => (string) $review->collector_id,
                    'decided_by' => (string) $review->decided_by, 'decided_at' => (string) $review->decided_at,
                    'payment_date' => $review->payment_date, 'evidence_reference' => $review->evidence_reference,
                    'bank_clearance_reference' => $review->bank_clearance_reference, 'evidence' => $review->evidence];
            }
        } elseif ($posting->kind === 'late_cash_receipt') {
            $doc = $this->row('treasury_late_cash_receipts', (int) $posting->treasury_late_cash_receipt_id);
            $review = $this->row('late_receipt_reviews', (int) $doc->late_receipt_review_id);
            $session = $this->row('cash_sessions', (int) $review->cash_session_id);
            $payment = Payment::query()->lockForUpdate()->find($review->payment_id);
            $this->need($payment !== null && $account->channel === 'cash'
                && app(LateReceiptReviewService::class)->checkCashPosting($posting), 'collection_evidence_late_cash_mismatch');
            $source = [
                'receipt_id' => (string) $doc->id, 'review_id' => (string) $review->id,
                'session_id' => (string) $session->id, 'collector_id' => (string) $review->collector_id,
                'sync_uuid' => $review->sync_uuid, 'payment_date' => $review->payment_date,
                'settlement_method' => $review->settlement_method, 'evidence' => $doc->evidence,
            ];
        } else {
            throw new DomainException('collection_evidence_unsupported');
        }
        $entry = Transaction::where('reference_type', Payment::class)->where('reference_id', $payment->id)
            ->where('type', 'payment')->lockForUpdate()->sole();
        $leg = app(FinancialLedgerEvidence::class)->leg((int) $entry->id);
        $this->need($leg['amount_minor'] === $minor, 'collection_evidence_ledger_mismatch');
        $bytes = [
            'format' => 1, 'posting_id' => (string) $postingId, 'kind' => $posting->kind,
            'account_id' => (string) $account->id, 'amount_minor' => (string) $minor,
            'effective_on' => $posting->effective_on, 'reference' => $posting->evidence_reference,
            'actor_id' => (string) $posting->posted_by, 'payment_id' => (string) $payment->id,
            'subscriber_id' => (string) $payment->subscriber_id, 'ledger_hash' => $leg['source_hash'],
            'source' => $source, 'currency' => 'YER',
        ];
        return ['source_type' => 'treasury_posting', 'source_id' => (string) $postingId,
            'leg' => 'treasury', 'amount_minor' => $minor, 'currency' => 'YER',
            'source_hash' => hash('sha256', json_encode($bytes, JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
    }

    private function row(string $table, int $id): object
    {
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        if ($row === null) throw new DomainException('collection_evidence_missing');
        return $row;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
