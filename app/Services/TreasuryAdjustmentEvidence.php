<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\TreasuryMoney;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Actual bank-charge and investigated cash-count adjustment evidence.
 * Read-only; requires the scope-first transaction. No reconciliation certification.
 */
final class TreasuryAdjustmentEvidence
{
    public function leg(int $postingId): array
    {
        $this->need(DB::transactionLevel() > 0 && $postingId > 0, 'adjustment_evidence_requires_gate');
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'adjustment_evidence_issuance_lock');
        $posting = $this->row('treasury_postings', $postingId);
        $account = $this->row('treasury_accounts', (int) $posting->treasury_account_id);
        $minor = TreasuryMoney::signed($posting->amount_minor);
        $this->need($minor !== 0 && (int) $posting->posted_by > 0, 'adjustment_evidence_invalid');
        if ($posting->kind === 'bank_fee') {
            $fee = $this->row('treasury_bank_fees', (int) $posting->treasury_bank_fee_id);
            $line = $this->row('bank_statement_lines', (int) $fee->bank_statement_line_id);
            $statement = $this->row('bank_statements', (int) $line->bank_statement_id);
            DB::table('bank_statement_lines')->where('bank_statement_id', $statement->id)
                ->orderBy('id')->lockForUpdate()->get();
            $match = DB::table('bank_statement_matches')->where('bank_statement_line_id', $line->id)
                ->lockForUpdate()->first();
            $this->need($match !== null && $minor < 0 && $account->channel === 'bank'
                && app(BankReconciliationService::class)->checkFeePosting($posting), 'adjustment_evidence_fee_mismatch');
            $source = [
                'fee_id' => (string) $fee->id, 'statement_id' => (string) $statement->id,
                'statement_hash' => $statement->content_hash, 'line_id' => (string) $line->id,
                'bank_reference' => $line->bank_reference, 'match_id' => (string) $match->id,
                'imported_by' => (string) $statement->imported_by, 'matched_by' => (string) $match->matched_by,
                'reason' => $fee->reason, 'evidence' => $fee->evidence,
            ];
        } elseif ($posting->kind === 'cash_count_adjustment') {
            $adjustment = $this->row('treasury_cash_adjustments', (int) $posting->treasury_cash_adjustment_id);
            $count = $this->row('treasury_cash_counts', (int) $adjustment->treasury_cash_count_id);
            $review = $this->row('treasury_cash_count_reviews', (int) $adjustment->treasury_cash_count_review_id);
            DB::table('treasury_postings')->where('treasury_account_id', $account->id)
                ->where('id', '<', $posting->id)->orderBy('id')->lockForUpdate()->get();
            $this->need($account->channel === 'cash'
                && app(TreasuryCashCountService::class)->checkAdjustmentPosting($posting),
                'adjustment_evidence_cash_mismatch');
            $source = [
                'adjustment_id' => (string) $adjustment->id, 'classification' => $adjustment->classification,
                'count_id' => (string) $count->id, 'counted_by' => (string) $count->counted_by,
                'count_reference' => $count->reference, 'observed_at' => (string) $count->observed_at,
                'counted_minor' => (string) TreasuryMoney::document($count->counted_minor),
                'recorded_minor' => (string) TreasuryMoney::signed($count->recorded_minor),
                'variance_minor' => (string) TreasuryMoney::signed($count->variance_minor),
                'ledger_hash' => $count->ledger_hash, 'count_evidence' => $count->evidence,
                'review_id' => (string) $review->id, 'reviewed_by' => (string) $review->reviewed_by,
                'review_evidence' => $review->evidence,
                'reason' => $adjustment->reason, 'evidence' => $adjustment->evidence,
            ];
        } else {
            throw new DomainException('adjustment_evidence_unsupported');
        }
        $bytes = [
            'format' => 1, 'posting_id' => (string) $postingId, 'kind' => $posting->kind,
            'account_id' => (string) $account->id, 'amount_minor' => (string) $minor,
            'effective_on' => $posting->effective_on, 'reference' => $posting->evidence_reference,
            'actor_id' => (string) $posting->posted_by, 'source' => $source, 'currency' => 'YER',
        ];
        return ['source_type' => 'treasury_posting', 'source_id' => (string) $postingId,
            'leg' => 'treasury', 'amount_minor' => $minor, 'currency' => 'YER',
            'source_hash' => hash('sha256', json_encode($bytes, JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
    }

    private function row(string $table, int $id): object
    {
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        if ($row === null) throw new DomainException('adjustment_evidence_missing');
        return $row;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
