<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
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
        return [
            'operation_uuid' => $payment->sync_uuid, 'payment_id' => $paymentId,
            'transaction_id' => (int) $subscriberLeg['source_id'], 'posting_id' => (int) $bankLeg['source_id'],
            'amount_minor' => $minor, 'effective_on' => $date,
            'pair_verified' => true, 'source_coverage_verified' => false, 'certified_balance' => false,
        ];
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
