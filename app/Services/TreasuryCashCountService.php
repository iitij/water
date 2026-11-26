<?php

namespace App\Services;

use App\Models\User;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\SignedMinor;
use App\Support\TreasuryMoney as Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Physical observations and independent review, not a balance adjustment or close. */
final class TreasuryCashCountService
{
    private function lock(): void
    {
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new \LogicException('Financial issuance lock is missing.');
        }
    }

    private function account(int $id): object
    {
        $account = DB::table('treasury_accounts')->where('id', $id)->lockForUpdate()->first();
        abort_unless($account, 404);
        if ($account->channel !== 'cash' || $account->opening_on > today()->toDateString()) {
            throw ValidationException::withMessages(['account' => 'يلزم حساب خزينة نقدية مفتوح.']);
        }
        return $account;
    }

    private function evidence(string $value): string
    {
        $value = trim($value);
        if (mb_strlen($value) < 10 || mb_strlen($value) > 2000) {
            throw ValidationException::withMessages(['evidence' => 'يلزم دليل جرد أو مراجعة مستقل.']);
        }
        return $value;
    }

    private function snapshot(object $account, ?int $beforePostingId = null): array
    {
        $query = DB::table('treasury_postings')->where('treasury_account_id', $account->id);
        if ($beforePostingId !== null) $query->where('id', '<', $beforePostingId);
        $rows = $query->orderBy('id')->get();
        // Preserve existing hashes for rows preceding this migration.
        foreach ($rows as $row) {
            if ($row->treasury_cash_adjustment_id === null) unset($row->treasury_cash_adjustment_id);
            if ($row->treasury_bank_collection_id === null) unset($row->treasury_bank_collection_id);
            if ($row->treasury_late_cash_receipt_id === null) unset($row->treasury_late_cash_receipt_id);
            if ($row->held_cash_payout_uuid === null) unset($row->held_cash_payout_uuid);
        }
        $balance = Money::document($account->opening_minor);
        foreach ($rows as $row) {
            $balance = SignedMinor::add($balance, Money::signed($row->amount_minor));
        }
        // Include all stored fields, not just the net amount. The register lock
        // prevents concurrent service writes while the observation is recorded.
        return [
            'recorded_minor' => $balance,
            'ledger_hash' => hash('sha256', json_encode([(array) $account, $rows->toArray()], JSON_THROW_ON_ERROR)),
        ];
    }

    /** Fresh reviewed equal observation, under the shared issuance fence. */
    public function assertFreshEqualObservation(int $accountId, int $countId, string $date): void
    {
        $this->assertFreshObservationVariance($accountId, $countId, $date, 0);
    }

    /** Exact accepted observation against the current ledger snapshot. */
    public function assertFreshObservationVariance(int $accountId, int $countId, string $date, int $variance): void
    {
        if (DB::transactionLevel() <= 0) throw new \DomainException('held_cash_reservation_context');
        $this->lock();
        $account = $this->account($accountId);
        $latest = DB::table('treasury_cash_counts')->where('treasury_account_id', $accountId)
            ->orderByDesc('id')->lockForUpdate()->first();
        if (! $latest || (int) $latest->id !== $countId
            || \Carbon\CarbonImmutable::parse($latest->observed_at)->toDateString() !== $date) {
            throw new \DomainException('completed_refund_resolution_stale_count');
        }
        $report = $this->report($latest, $account);
        if ($report['status'] !== 'accepted_observation' || ! $report['ledger_snapshot_matches']
            || Money::signed($latest->variance_minor) !== $variance || $report['adjustment'] !== null) {
            throw new \DomainException('completed_refund_resolution_stale_count');
        }
    }

    public function record(User $actor, int $id, string $amount, string $reference, string $evidence): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $reference = mb_strtoupper(trim($reference));
        $evidence = $this->evidence($evidence);
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/D', $amount)
            || mb_strlen($reference) < 3 || mb_strlen($reference) > 150) {
            throw ValidationException::withMessages(['count' => 'مبلغ الجرد أو مرجع الإثبات غير صالح.']);
        }
        $minor = Cash::input($amount, 'count', DecimalDomain::D14, digitsOnly: true);
        return DB::transaction(function () use ($actor, $id, $minor, $reference, $evidence): array {
            $this->lock();
            $account = $this->account($id);
            $existing = DB::table('treasury_cash_counts')->where('reference', $reference)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->treasury_account_id === $id
                    && (int) $existing->counted_by === (int) $actor->id
                    && Money::document($existing->counted_minor) === $minor && $existing->evidence === $evidence,
                    409, 'مرجع الجرد مرتبط بعملية مختلفة.');
                return $this->report($existing, $account);
            }
            $snapshot = $this->snapshot($account);
            $record = [
                'treasury_account_id' => $id, 'reference' => $reference,
                'counted_minor' => $minor, 'variance_minor' => Money::variance($minor, $snapshot['recorded_minor']),
                'evidence' => $evidence, 'counted_by' => $actor->id, 'observed_at' => now(),
            ] + $snapshot;
            $countId = DB::table('treasury_cash_counts')->insertGetId($record + ['created_at' => now(), 'updated_at' => now()]);
            app(TreasuryRegisterService::class)->requiredAudit('treasury.cash_count_recorded', [
                'entity_type' => 'treasury_cash_count', 'entity_id' => $countId,
                'actor_user_id' => $actor->id, 'after' => $record,
            ]);
            return $this->report(DB::table('treasury_cash_counts')->find($countId), $account);
        }, 3);
    }

    private function report(object $count, object $account): array
    {
        $review = DB::table('treasury_cash_count_reviews')->where('treasury_cash_count_id', $count->id)->first();
        $snapshot = $this->snapshot($account);
        $variance = Money::signed($count->variance_minor);
        $consistent = $variance === Money::variance($count->counted_minor, $count->recorded_minor)
            && (! $review || (int) $review->reviewed_by !== (int) $count->counted_by);
        $same = hash_equals($count->ledger_hash, $snapshot['ledger_hash'])
            && Money::signed($count->recorded_minor) === $snapshot['recorded_minor'];
        $adjustment = DB::table('treasury_cash_adjustments')->where('treasury_cash_count_id', $count->id)->first();
        $posting = $adjustment ? DB::table('treasury_postings')->where('treasury_cash_adjustment_id', $adjustment->id)->first() : null;
        $adjusted = $posting && $this->checkAdjustmentPosting($posting);
        return [
            'adjustment' => $adjustment ? (array) $adjustment : null,
            'adjustment_status' => $adjustment ? ($adjusted ? 'posted' : 'inconsistent') : ($variance === 0 ? 'not_required' : 'unresolved'),
            'count' => (array) $count,
            'review' => $review ? (array) $review : null,
            'status' => ! $consistent ? 'inconsistent' : ($review ? ((bool) $review->accepted ? 'accepted_observation' : 'rejected_observation') : 'pending_review'),
            'ledger_snapshot_matches' => $same,
            'current_recorded_minor' => $snapshot['recorded_minor'],
            'variance_status' => ! $consistent ? 'inconsistent' : ($variance === 0 ? 'equal' : ($variance > 0 ? 'surplus' : 'shortage')),
            'certified_balance' => false,
            'period_closed' => false,
        ];
    }

    public function review(User $actor, int $id, bool $accept, string $evidence): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $evidence = $this->evidence($evidence);
        return DB::transaction(function () use ($actor, $id, $accept, $evidence): array {
            $this->lock();
            $count = DB::table('treasury_cash_counts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($count, 404);
            $account = $this->account((int) $count->treasury_account_id);
            abort_if((int) $count->counted_by === (int) $actor->id, 403, 'لا يجوز لمُسجّل الجرد مراجعة جرده بنفسه.');
            $report = $this->report($count, $account);
            if ($report['review']) {
                $review = $report['review'];
                abort_unless((int) $review['reviewed_by'] === (int) $actor->id
                    && (bool) $review['accepted'] === $accept && $review['evidence'] === $evidence,
                    409, 'سبق تسجيل قرار مختلف.');
                return $report;
            }
            abort_unless($report['status'] === 'pending_review' && $report['ledger_snapshot_matches'],
                409, 'تغيرت القيود أو بيانات الجرد؛ يلزم جرد جديد مستقل.');
            $record = [
                'treasury_cash_count_id' => $id, 'reviewed_by' => $actor->id,
                'accepted' => $accept, 'evidence' => $evidence,
            ];
            $reviewId = DB::table('treasury_cash_count_reviews')->insertGetId($record + ['created_at' => now(), 'updated_at' => now()]);
            app(TreasuryRegisterService::class)->requiredAudit('treasury.cash_count_reviewed', [
                'entity_type' => 'treasury_cash_count_review', 'entity_id' => $reviewId,
                'actor_user_id' => $actor->id, 'after' => $record + ['financial_adjustment_created' => false],
            ]);
            return $this->report($count, $account);
        }, 3);
    }

    /** Records a proven cause, not an arbitrary balancing plug or a complete GL journal. */
    public function adjust(User $actor, int $id, string $reference, string $classification, string $reason, string $evidence): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $reference = mb_strtoupper(trim($reference));
        $reason = trim($reason);
        $evidence = $this->evidence($evidence);
        if (mb_strlen($reference) < 3 || mb_strlen($reference) > 150
            || mb_strlen($reason) < 20 || mb_strlen($reason) > 500
            || ! in_array($classification, ['confirmed_cash_loss', 'unattributed_cash_held'], true)) {
            throw ValidationException::withMessages(['adjustment' => 'يلزم تصنيف محدد وسبب مثبت ومرجع مستقل.']);
        }
        return DB::transaction(function () use ($actor, $id, $reference, $classification, $reason, $evidence): array {
            $this->lock();
            $count = DB::table('treasury_cash_counts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($count, 404);
            $account = $this->account((int) $count->treasury_account_id);
            abort_if((int) $count->counted_by === (int) $actor->id, 403, 'يلزم اعتماد مستقل عن مسجل الجرد.');
            $review = DB::table('treasury_cash_count_reviews')->where('treasury_cash_count_id', $id)->first();
            $variance = Money::signed($count->variance_minor);
            if (($classification === 'confirmed_cash_loss' && $variance >= 0)
                || ($classification === 'unattributed_cash_held' && $variance <= 0)) {
                throw ValidationException::withMessages(['classification' => 'التصنيف لا يطابق اتجاه فرق الجرد غير الصفري.']);
            }
            $record = [
                'treasury_account_id' => (int) $account->id, 'treasury_cash_count_id' => $id,
                'treasury_cash_count_review_id' => $review?->id, 'amount_minor' => $variance,
                'classification' => $classification, 'reference' => $reference, 'reason' => $reason,
                'evidence' => $evidence, 'approved_by' => $actor->id,
            ];
            $existing = DB::table('treasury_cash_adjustments')->where('treasury_cash_count_id', $id)->lockForUpdate()->first();
            if ($existing) {
                foreach ($record as $field => $value) {
                    abort_unless((string) $existing->$field === (string) $value, 409, 'سبق تسجيل تسوية مختلفة للجرد.');
                }
                $posting = DB::table('treasury_postings')->where('treasury_cash_adjustment_id', $existing->id)->first();
                abort_unless($posting && $this->checkAdjustmentPosting($posting), 409, 'مستند التسوية السابق غير متطابق.');
                return ['adjustment' => (array) $existing, 'posting' => (array) $posting, 'observation' => $this->report($count, $account)];
            }
            $snapshot = $this->snapshot($account);
            abort_unless($review && (bool) $review->accepted
                && (int) $review->reviewed_by !== (int) $count->counted_by
                && $variance === Money::variance($count->counted_minor, $count->recorded_minor)
                && Money::signed($count->recorded_minor) === $snapshot['recorded_minor']
                && hash_equals($count->ledger_hash, $snapshot['ledger_hash'])
                && \Illuminate\Support\Carbon::parse($count->observed_at)->toDateString() === today()->toDateString(),
                409, 'يلزم جرد مقبول وحديث لم تتغير قيوده.');
            abort_if(DB::table('treasury_cash_counts')->where('treasury_account_id', $account->id)->where('id', '>', $id)->exists(),
                409, 'يوجد جرد أحدث؛ يجب مراجعته أولاً.');
            // Existing register is provisional: refuse known source gaps rather than
            // convert missing cash documents into an apparent loss or surplus.
            $register = app(TreasuryRegisterService::class)->inspect($actor, (int) $account->id);
            // A proven liquidity deficit is an accounting condition, not missing
            // evidence. Recording a further independently observed loss must not
            // hide that loss. All source defects and pending documents still block
            // adjustment; the ordinary spending reserve remains unchanged.
            $blockingIssues = array_filter($register['issues'], static fn (array $issue): bool =>
                ! ($classification === 'confirmed_cash_loss' && $variance < 0
                    && ($issue['code'] ?? null) === 'held_cash_possible_liquidity_shortfall'));
            abort_if($blockingIssues || $register['channel_unposted_handover_count'] || $register['channel_unposted_refund_count'] || $register['channel_unposted_late_cash_count'],
                409, 'توجد حركات غير مرحلة أو خلل في دفتر الخزينة؛ يلزم معالجتها أولاً.');
            abort_if(DB::table('treasury_cash_adjustments')->where('reference', $reference)->exists()
                || DB::table('treasury_cash_counts')->where('reference', $reference)->exists()
                || DB::table('treasury_accounts')->where('opening_reference', $reference)->exists()
                || DB::table('treasury_postings')->where('evidence_reference', $reference)->exists()
                || DB::table('cash_handovers')->where('reference', $reference)->exists()
                || DB::table('payment_refunds')->where('disbursement_reference', $reference)->exists()
                || DB::table('late_receipt_reviews')->where('evidence_reference', $reference)->exists(),
                409, 'مرجع التسوية مستخدم مسبقاً.');
            $date = today()->toDateString();
            $adjustmentId = DB::table('treasury_cash_adjustments')->insertGetId($record + [
                'adjusted_on' => $date, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $postingId = DB::table('treasury_postings')->insertGetId([
                'treasury_account_id' => $account->id, 'treasury_cash_adjustment_id' => $adjustmentId,
                'kind' => 'cash_count_adjustment', 'amount_minor' => $variance, 'effective_on' => $date,
                'evidence_reference' => $reference, 'posted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $posting = DB::table('treasury_postings')->find($postingId);
            if (! $this->checkAdjustmentPosting($posting)) throw new \LogicException('Cash adjustment source is inconsistent.');
            app(TreasuryRegisterService::class)->requiredAudit('treasury.cash_count_adjusted', [
                'entity_type' => 'treasury_cash_adjustment', 'entity_id' => $adjustmentId, 'actor_user_id' => $actor->id,
                'after' => $record + ['adjusted_on' => $date, 'posting_id' => $postingId, 'subscriber_posting_created' => false],
            ]);
            return ['adjustment' => (array) DB::table('treasury_cash_adjustments')->find($adjustmentId),
                'posting' => (array) $posting, 'observation' => $this->report($count, $account)];
        }, 3);
    }

    public function checkAdjustmentPosting(object $posting): bool
    {
        try {
            return $this->checkAdjustmentPostingExact($posting);
        } catch (\InvalidArgumentException|\OverflowException) {
            return false;
        }
    }

    private function checkAdjustmentPostingExact(object $posting): bool
    {
        if ($posting->kind !== 'cash_count_adjustment' || $posting->held_cash_payout_uuid !== null || ! $posting->treasury_cash_adjustment_id
            || $posting->cash_handover_id || $posting->payment_refund_id || $posting->cash_session_funding_id
            || $posting->treasury_transfer_id || $posting->treasury_transfer_receipt_id || $posting->treasury_bank_fee_id || $posting->treasury_bank_collection_id || $posting->treasury_late_cash_receipt_id) return false;
        $adjustment = DB::table('treasury_cash_adjustments')->find($posting->treasury_cash_adjustment_id);
        $count = $adjustment ? DB::table('treasury_cash_counts')->find($adjustment->treasury_cash_count_id) : null;
        $review = $adjustment ? DB::table('treasury_cash_count_reviews')->find($adjustment->treasury_cash_count_review_id) : null;
        $account = $adjustment ? DB::table('treasury_accounts')->find($adjustment->treasury_account_id) : null;
        if (! $adjustment || ! $count || ! $review || ! $account || $account->channel !== 'cash') return false;
        $prior = $this->snapshot($account, (int) $posting->id);
        $variance = Money::signed($count->variance_minor);
        return (int) $count->treasury_account_id === (int) $account->id
            && (int) $posting->treasury_account_id === (int) $account->id
            && (int) $review->treasury_cash_count_id === (int) $count->id
            && (bool) $review->accepted && (int) $review->reviewed_by !== (int) $count->counted_by
            && (int) $adjustment->approved_by !== (int) $count->counted_by
            && (int) $posting->posted_by === (int) $adjustment->approved_by
            && $variance === Money::variance($count->counted_minor, $count->recorded_minor)
            && Money::signed($count->recorded_minor) === $prior['recorded_minor'] && hash_equals($count->ledger_hash, $prior['ledger_hash'])
            && (($variance < 0 && $adjustment->classification === 'confirmed_cash_loss')
                || ($variance > 0 && $adjustment->classification === 'unattributed_cash_held'))
            && Money::signed($adjustment->amount_minor) === $variance && Money::signed($posting->amount_minor) === $variance
            && $adjustment->adjusted_on === $posting->effective_on
            && $adjustment->adjusted_on === \Illuminate\Support\Carbon::parse($count->observed_at)->toDateString()
            && $posting->effective_on >= $account->opening_on && $posting->effective_on <= today()->toDateString()
            && $posting->evidence_reference === $adjustment->reference
            && mb_strlen(trim($adjustment->reason)) >= 20 && mb_strlen(trim($adjustment->evidence)) >= 10;
    }

    public function inspect(User $actor, int $id): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        return DB::transaction(function () use ($id): array {
            $this->lock();
            $count = DB::table('treasury_cash_counts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($count, 404);
            return $this->report($count, $this->account((int) $count->treasury_account_id));
        }, 3);
    }

    public function index(User $actor, int $id): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        return DB::transaction(function () use ($id): array {
            $this->lock();
            $this->account($id);
            return ['counts' => DB::table('treasury_cash_counts')->where('treasury_account_id', $id)
                ->orderByDesc('id')->paginate(50), 'certified_balance' => false, 'period_closed' => false];
        }, 3);
    }
}
