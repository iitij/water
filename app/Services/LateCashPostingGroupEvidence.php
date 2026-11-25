<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\Transaction;
use App\Support\LateCashPostingGroup;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Inactive source adapter. Derive the format 2 proof from actual linked
 * documents, not caller-selected ledger IDs, amounts or effective dates.
 * Caller owns both open-period locks via withinOpenPeriods. No insertion,
 * historic adoption, authorization, or closure certificate.
 */
final class LateCashPostingGroupEvidence
{
    public function __construct(
        private LateCashPostingGroup $group,
        private FinancialLedgerEvidence $ledger,
        private TreasuryCollectionEvidence $treasury,
    ) {}

    public function derive(int $reviewId, array $contexts): array
    {
        return $this->deriveWithPeriodPolicy($reviewId, $contexts, false);
    }

    /**
     * Reconstruct existing evidence using original posting counters. This
     * read-only path is not an authorization to insert, replay or reopen.
     * Unsupported later source transitions still fail closed.
     */
    public function deriveHistorical(int $reviewId, array $contexts): array
    {
        return $this->deriveWithPeriodPolicy($reviewId, $contexts, true);
    }

    private function deriveWithPeriodPolicy(int $reviewId, array $contexts, bool $historical): array
    {
        $this->need(DB::transactionLevel() > 0 && $reviewId > 0, 'late_cash_group_requires_gate');
        $this->need(count($contexts) === 2, 'late_cash_group_requires_two_dates');
        $scope = DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first();
        $this->need($scope !== null && $scope->mode === 'ACTIVE' && ($historical || $scope->active_freeze_uuid === null)
            && $scope->currency === 'YER' && $scope->timezone === 'Asia/Aden'
            && (string) $scope->calendar_version === '1', 'late_cash_group_scope');
        ksort($contexts, SORT_STRING);
        foreach ($contexts as $date => $context) {
            $this->need(is_array($context) && ($context['scope'] ?? null) === 'water'
                && ($context['effective_on'] ?? null) === $date && is_int($context['period_id'] ?? null)
                && is_int($context['epoch'] ?? null) && $context['epoch'] >= 0
                && is_int($context['version'] ?? null) && $context['version'] >= 0
                && ($historical ? $context['epoch'] <= $this->counter($scope->fence_epoch)
                    : $context['epoch'] === $this->counter($scope->fence_epoch)), 'late_cash_group_context');
            $period = DB::table('financial_periods')->where('id', $context['period_id'])->lockForUpdate()->first();
            $this->need($period !== null && $period->scope_key === 'water'
                && ($historical ? in_array($period->state, ['OPEN', 'FROZEN', 'CLOSED'], true) : $period->state === 'OPEN')
                && ($historical ? $context['version'] <= $this->counter($period->version)
                    : $context['version'] === $this->counter($period->version))
                && $date >= $period->start_on && $date < $period->next_on, 'late_cash_group_context');
            $expected = app(\App\Support\FinancialClosePolicy::class)->open('water', $period->month, $scope->timezone);
            $this->need($period->start_on === $expected['start'] && $period->next_on === $expected['next'],
                'late_cash_group_context');
        }
        $this->need(DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first() !== null,
            'late_cash_group_issuance_lock');
        $review = DB::table('late_receipt_reviews')->where('id', $reviewId)->lockForUpdate()->first();
        $this->need($review !== null && $review->status === 'approved'
            && $review->settlement_method === 'separate_handover'
            && $review->payment_method === 'cash' && (int) $review->payment_id > 0
            && is_string($review->sync_uuid), 'late_cash_group_review');
        $payment = Payment::query()->lockForUpdate()->find($review->payment_id);
        $this->need($payment !== null && $payment->sync_uuid === $review->sync_uuid
            && ! $payment->isVoided() && ! $payment->hasPendingVoidRequest()
            && $payment->payment_method === 'cash' && $payment->cash_session_id === null
            && $payment->payment_date->toDateString() === $review->payment_date, 'late_cash_group_payment');
        $otherReviews = DB::table('late_receipt_reviews')->where('payment_id', $payment->id)
            ->orderBy('id')->lockForUpdate()->get();
        $claims = DB::table('noncash_collection_claims')->where('payment_id', $payment->id)
            ->orderBy('id')->lockForUpdate()->get();
        $this->need($otherReviews->count() === 1 && (int) $otherReviews->first()->id === $reviewId
            && $claims->isEmpty(), 'late_cash_group_origin_conflict');
        $receipts = DB::table('treasury_late_cash_receipts')->where('late_receipt_review_id', $reviewId)
            ->lockForUpdate()->get();
        $this->need($receipts->count() === 1, 'late_cash_group_receipt');
        $receipt = $receipts->first();
        $postings = DB::table('treasury_postings')->where('treasury_late_cash_receipt_id', $receipt->id)
            ->lockForUpdate()->get();
        $entries = Transaction::withTrashed()->where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)->where('type', 'payment')->lockForUpdate()->get();
        $this->need($postings->count() === 1 && $entries->count() === 1 && ! $entries->first()->trashed(),
            'late_cash_group_effects');
        $posting = $postings->first();
        $this->need($posting->kind === 'late_cash_receipt' && $receipt->received_on === $posting->effective_on
            && $review->payment_date < $receipt->received_on
            && isset($contexts[$review->payment_date], $contexts[$receipt->received_on]), 'late_cash_group_date_mismatch');
        return $this->group->build($review->sync_uuid, $reviewId, (int) $payment->id, (int) $receipt->id,
            $contexts[$review->payment_date], $contexts[$receipt->received_on],
            $this->ledger->leg((int) $entries->first()->id), $this->treasury->leg((int) $posting->id));
    }

    private function counter(mixed $value): int
    {
        $this->need(is_int($value) || is_string($value), 'late_cash_group_counter');
        $text = (string) $value;
        $this->need(preg_match('/\A(?:0|[1-9][0-9]*)\z/', $text) === 1
            && (string) (int) $text === $text, 'late_cash_group_counter');
        return (int) $text;
    }

    private function need(bool $ok, string $reason): void
    {
        if (! $ok) throw new DomainException($reason);
    }
}
