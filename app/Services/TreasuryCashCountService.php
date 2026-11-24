<?php

namespace App\Services;

use App\Models\User;
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

    private function snapshot(object $account): array
    {
        $rows = DB::table('treasury_postings')->where('treasury_account_id', $account->id)
            ->orderBy('id')->get();
        $balance = (int) $account->opening_minor;
        foreach ($rows as $row) {
            $balance += (int) $row->amount_minor;
        }
        // Include all stored fields, not just the net amount. The register lock
        // prevents concurrent service writes while the observation is recorded.
        return [
            'recorded_minor' => $balance,
            'ledger_hash' => hash('sha256', json_encode([(array) $account, $rows->toArray()], JSON_THROW_ON_ERROR)),
        ];
    }

    public function record(User $actor, int $id, string $amount, string $reference, string $evidence): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $reference = mb_strtoupper(trim($reference));
        $evidence = $this->evidence($evidence);
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/D', $amount, $parts)
            || mb_strlen($reference) < 3 || mb_strlen($reference) > 150) {
            throw ValidationException::withMessages(['count' => 'مبلغ الجرد أو مرجع الإثبات غير صالح.']);
        }
        $minor = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        return DB::transaction(function () use ($actor, $id, $minor, $reference, $evidence): array {
            $this->lock();
            $account = $this->account($id);
            $existing = DB::table('treasury_cash_counts')->where('reference', $reference)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->treasury_account_id === $id
                    && (int) $existing->counted_by === (int) $actor->id
                    && (int) $existing->counted_minor === $minor && $existing->evidence === $evidence,
                    409, 'مرجع الجرد مرتبط بعملية مختلفة.');
                return $this->report($existing, $account);
            }
            $snapshot = $this->snapshot($account);
            $record = [
                'treasury_account_id' => $id, 'reference' => $reference,
                'counted_minor' => $minor, 'variance_minor' => $minor - $snapshot['recorded_minor'],
                'evidence' => $evidence, 'counted_by' => $actor->id, 'observed_at' => now(),
            ] + $snapshot;
            $countId = DB::table('treasury_cash_counts')->insertGetId($record + ['created_at' => now(), 'updated_at' => now()]);
            app(AuditLogger::class)->recordRequired('treasury.cash_count_recorded', [
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
        $consistent = (int) $count->counted_minor >= 0
            && (int) $count->variance_minor === (int) $count->counted_minor - (int) $count->recorded_minor
            && (! $review || (int) $review->reviewed_by !== (int) $count->counted_by);
        $same = hash_equals($count->ledger_hash, $snapshot['ledger_hash'])
            && (int) $count->recorded_minor === $snapshot['recorded_minor'];
        return [
            'count' => (array) $count,
            'review' => $review ? (array) $review : null,
            'status' => ! $consistent ? 'inconsistent' : ($review ? ((bool) $review->accepted ? 'accepted_observation' : 'rejected_observation') : 'pending_review'),
            'ledger_snapshot_matches' => $same,
            'current_recorded_minor' => $snapshot['recorded_minor'],
            'variance_status' => ! $consistent ? 'inconsistent' : ((int) $count->variance_minor === 0 ? 'equal' : ((int) $count->variance_minor > 0 ? 'surplus' : 'shortage')),
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
            app(AuditLogger::class)->recordRequired('treasury.cash_count_reviewed', [
                'entity_type' => 'treasury_cash_count_review', 'entity_id' => $reviewId,
                'actor_user_id' => $actor->id, 'after' => $record + ['financial_adjustment_created' => false],
            ]);
            return $this->report($count, $account);
        }, 3);
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
