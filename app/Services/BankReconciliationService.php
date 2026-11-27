<?php

namespace App\Services;

use App\Models\User;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Support\DecimalDomain;
use App\Support\MoneyBoundary;
use App\Support\SignedMinor;
use App\Support\TreasuryMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Evidence matching, not permission to alter money or certify a partial treasury register. */
final class BankReconciliationService
{
    /** Evidence writes must serialize with close and cover every month in the statement.
     * A late statement may be received for an open earlier month, never a closed one.
     * No posting or accounting balance is created by this transport.
     */
    private function evidenceTransaction(User $actor, string $from, string $to, callable $work): array
    {
        if (config('financial_candidate.bank_evidence') !== true) {
            return DB::transaction(fn () => $work($actor), 3);
        }
        abort_unless(app()->environment(['testing', 'staging']), 503);
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', $to, now()->toDateTimeImmutable(),
            function () use ($actor, $from, $to, $work): array {
                $this->assertEvidenceRangeOpen($from, $to);
                $this->lock();
                $current = User::query()->lockForUpdate()->findOrFail($actor->id);
                abort_if($current->isDisabled(), 403);
                $current->setRelation('roles', $current->roles()->lockForUpdate()->get());
                app(TreasuryRegisterService::class)->authorize($current);
                return $work($current);
            });
    }

    private function assertEvidenceRangeOpen(string $from, string $to): void
    {
        $context = FinancialWriteGate::currentContext();
        if ($context['scope'] !== 'water' || $from > $to) {
            throw new \DomainException('bank_evidence_period_range_invalid');
        }
        $periods = DB::table('financial_periods')->where('scope_key', 'water')
            ->where('start_on', '<=', $to)->where('next_on', '>', $from)
            ->orderBy('start_on')->lockForUpdate()->get();
        $cursor = $from;
        foreach ($periods as $period) {
            $expected = app(\App\Support\FinancialClosePolicy::class)->open('water', $period->month, 'Asia/Aden');
            if ($period->start_on !== $expected['start'] || $period->next_on !== $expected['next']
                || $period->start_on > $cursor || $period->next_on <= $cursor || $period->state !== 'OPEN'
                || DB::table('financial_period_closures')->where('period_id', $period->id)->lockForUpdate()->first()
                || DB::table('financial_close_commands')->where('period_id', $period->id)
                    ->where('action', 'approve')->lockForUpdate()->first()) {
                throw new \DomainException('bank_evidence_period_range_not_open');
            }
            $cursor = $period->next_on;
        }
        if ($cursor <= $to) throw new \DomainException('bank_evidence_period_range_not_open');
    }

    private function lock(): void
    {
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new \LogicException('Financial issuance lock is missing.');
        }
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function minor(mixed $amount): int
    {
        try {
            return MoneyBoundary::regexInput($amount, DecimalDomain::D14, true, -DecimalDomain::D14->maxMinor());
        } catch (\InvalidArgumentException|\OverflowException) {
            $this->reject('amount', 'يلزم مبلغ صحيح بحد أقصى منزلتين عشريتين.');
        }
    }

    private function bankMinor(mixed $raw): int
    {
        return MoneyBoundary::readMinor($raw, false, DecimalDomain::D14);
    }

    private function receiptMinor(mixed $raw): int
    {
        return \App\Support\CashCustodyAmounts::positiveStored($raw, DB::connection()->getDriverName(), DecimalDomain::D12);
    }

    private function sumRows(iterable $rows, bool $bank = false, int $opening = 0): int
    {
        foreach ($rows as $row) {
            $opening = SignedMinor::add($opening, $bank ? $this->bankMinor($row->amount_minor) : SignedMinor::parse($row->amount_minor));
        }
        return $opening;
    }

    private function date(string $date): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            || $date > now()->toDateString()) {
            $this->reject('date', 'تاريخ كشف البنك غير صالح أو مستقبلي.');
        }
    }

    private function account(int $id): object
    {
        $account = DB::table('treasury_accounts')->where('id', $id)->lockForUpdate()->first();
        abort_unless($account, 404);
        if ($account->channel !== 'bank') $this->reject('account', 'المطابقة تخص الحسابات البنكية فقط.');
        return $account;
    }

    public function import(User $actor, int $accountId, array $data): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $from = $data['from_date'];
        $to = $data['to_date'];
        $this->date($from);
        $this->date($to);
        $reference = mb_strtoupper(trim($data['reference']));
        $evidence = trim($data['evidence']);
        if ($from > $to || mb_strlen($reference) < 3 || mb_strlen($reference) > 150
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000
            || count($data['lines']) > 1000) {
            $this->reject('statement', 'بيانات الكشف أو الفترة أو الإثبات غير صالحة.');
        }
        $record = [
            'treasury_account_id' => $accountId, 'reference' => $reference,
            'from_date' => $from, 'to_date' => $to,
            'opening_minor' => $this->minor($data['opening_amount']),
            'closing_minor' => $this->minor($data['closing_amount']),
            'evidence' => $evidence, 'imported_by' => (int) $actor->id,
        ];
        $lines = [];
        $seen = [];
        $net = 0;
        foreach ($data['lines'] as $line) {
            $key = mb_strtoupper(trim($line['bank_reference']));
            $description = isset($line['description']) ? trim($line['description']) : null;
            $this->date($line['booked_on']);
            $amount = $this->minor($line['amount']);
            if ($amount === 0 || mb_strlen($key) < 1 || mb_strlen($key) > 150
                || isset($seen[$key]) || mb_strlen($description ?? '') > 500
                || $line['booked_on'] < $from || $line['booked_on'] > $to) {
                $this->reject('lines', 'حركة بنكية مكررة أو خارج الفترة أو غير صالحة.');
            }
            $seen[$key] = true;
            $net = SignedMinor::add($net, $amount);
            $lines[] = ['treasury_account_id' => $accountId, 'bank_reference' => $key,
                'booked_on' => $line['booked_on'], 'amount_minor' => $amount, 'description' => $description];
        }
        if (SignedMinor::add($record['opening_minor'], $net) !== $record['closing_minor']) {
            $this->reject('closing_amount', 'رصيد الإغلاق لا يساوي الافتتاح مضافاً إليه صافي حركات البنك.');
        }
        usort($lines, fn ($a, $b) => strcmp($a['bank_reference'], $b['bank_reference']));
        $record['content_hash'] = hash('sha256', json_encode([$record, $lines], JSON_THROW_ON_ERROR));
        return $this->evidenceTransaction($actor, $from, $to, function (User $actor) use ($accountId, $record, $lines, $from, $to): array {
            $this->lock();
            $account = $this->account($accountId);
            $existing = DB::table('bank_statements')->where('treasury_account_id', $accountId)
                ->where('reference', $record['reference'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals($existing->content_hash, $record['content_hash']), 409, 'مرجع الكشف مرتبط ببيانات مختلفة.');
                $report = $this->report($existing, $account);
                abort_if(! $report['statement_integrity_matches'], 409, 'بيانات الكشف المسجل لا تطابق بصمته الأصلية.');
                return $report;
            }
            if ($from < $account->opening_on) $this->reject('from_date', 'الفترة تسبق افتتاح الحساب.');
            $statements = DB::table('bank_statements')->where('treasury_account_id', $accountId);
            abort_if((clone $statements)->where('from_date', '<=', $to)->where('to_date', '>=', $from)->exists(),
                409, 'فترة كشف البنك تتداخل مع كشف مسجل.');
            $prior = (clone $statements)->where('to_date', \Illuminate\Support\Carbon::parse($from)->subDay()->toDateString())->first();
            $next = (clone $statements)->where('from_date', \Illuminate\Support\Carbon::parse($to)->addDay()->toDateString())->first();
            if (($prior && $this->bankMinor($prior->closing_minor) !== $record['opening_minor'])
                || ($next && $this->bankMinor($next->opening_minor) !== $record['closing_minor'])) {
                $this->reject('opening_amount', 'رصيد الكشف لا يتصل بالكشف البنكي المجاور.');
            }
            foreach (array_chunk(array_column($lines, 'bank_reference'), 200) as $keys) {
                abort_if(DB::table('bank_statement_lines')->where('treasury_account_id', $accountId)
                    ->whereIn('bank_reference', $keys)->exists(), 409, 'معرّف حركة البنك مستخدم مسبقاً.');
            }
            $id = DB::table('bank_statements')->insertGetId($record + ['created_at' => now(), 'updated_at' => now()]);
            foreach (array_chunk($lines, 100) as $chunk) {
                DB::table('bank_statement_lines')->insert(array_map(fn ($line) => $line + [
                    'bank_statement_id' => $id, 'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }
            app(TreasuryRegisterService::class)->requiredAudit('bank_statement.imported', [
                'entity_type' => 'bank_statement', 'entity_id' => $id, 'actor_user_id' => $actor->id,
                'after' => $record + ['line_count' => count($lines)],
            ]);
            return $this->report(DB::table('bank_statements')->find($id), $account);
        });
    }

    /** Rebuild the original canonical payload; offsetting edits cannot hide behind a zero net. */
    private function intact(object $statement, $lines): bool
    {
        try {
            return $this->intactExact($statement, $lines);
        } catch (\InvalidArgumentException|\OverflowException) {
            return false;
        }
    }

    private function intactExact(object $statement, $lines): bool
    {
        $record = [
            'treasury_account_id' => (int) $statement->treasury_account_id,
            'reference' => $statement->reference, 'from_date' => $statement->from_date,
            'to_date' => $statement->to_date, 'opening_minor' => $this->bankMinor($statement->opening_minor),
            'closing_minor' => $this->bankMinor($statement->closing_minor), 'evidence' => $statement->evidence,
            'imported_by' => (int) $statement->imported_by,
        ];
        $canonical = [];
        foreach ($lines as $line) {
            $canonical[] = [
                'treasury_account_id' => (int) $line->treasury_account_id,
                'bank_reference' => $line->bank_reference, 'booked_on' => $line->booked_on,
                'amount_minor' => $this->bankMinor($line->amount_minor), 'description' => $line->description,
            ];
            if ((int) $line->treasury_account_id !== (int) $statement->treasury_account_id
                || $line->booked_on < $statement->from_date || $line->booked_on > $statement->to_date
                || $this->bankMinor($line->amount_minor) === 0) return false;
        }
        usort($canonical, fn ($a, $b) => strcmp($a['bank_reference'], $b['bank_reference']));
        return hash_equals($statement->content_hash, hash('sha256', json_encode([$record, $canonical], JSON_THROW_ON_ERROR)));
    }

    private function valid(object $statement, object $line, ?object $posting, object $match): bool
    {
        try {
            return $this->validExact($statement, $line, $posting, $match);
        } catch (\InvalidArgumentException|\OverflowException) {
            return false;
        }
    }

    private function validExact(object $statement, object $line, ?object $posting, object $match): bool
    {
        if ($posting && ($posting->kind === 'bank_fee' || $posting->treasury_bank_fee_id)) {
            $fee = $posting->treasury_bank_fee_id ? DB::table('treasury_bank_fees')->find($posting->treasury_bank_fee_id) : null;
            if (! $fee || (int) $fee->bank_statement_line_id !== (int) $line->id
                || ! $this->validFeePosting($posting, $statement, $line, $match, $fee)) return false;
        }
        if ($posting && ($posting->kind === 'bank_collection' || $posting->treasury_bank_collection_id)) {
            $collection = $posting->treasury_bank_collection_id ? DB::table('treasury_bank_collections')->find($posting->treasury_bank_collection_id) : null;
            if (! $collection || (int) $collection->bank_statement_line_id !== (int) $line->id
                || ! $this->checkCollectionPosting($posting)) return false;
        }
        return $posting && (int) $line->bank_statement_id === (int) $statement->id
            && (int) $line->treasury_account_id === (int) $statement->treasury_account_id
            && (int) $posting->treasury_account_id === (int) $statement->treasury_account_id
            && $this->bankMinor($line->amount_minor) !== 0 && $this->bankMinor($line->amount_minor) === SignedMinor::parse($posting->amount_minor)
            && $line->booked_on >= $statement->from_date && $line->booked_on <= $statement->to_date
            && $posting->effective_on >= $statement->from_date && $posting->effective_on <= $statement->to_date
            && (int) $match->matched_by !== (int) $statement->imported_by
            && mb_strlen(trim($match->evidence)) >= 10;
    }

    public function matchLine(User $actor, int $id, int $lineId, int $postingId, string $evidence): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $evidence = trim($evidence);
        if (mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000) $this->reject('evidence', 'يلزم إثبات المطابقة.');
        $range = DB::table('bank_statements')->where('id', $id)->first(['from_date', 'to_date']);
        abort_unless($range, 404);
        return $this->evidenceTransaction($actor, $range->from_date, $range->to_date,
            function (User $actor) use ($id, $lineId, $postingId, $evidence, $range): array {
            $this->lock();
            $statement = DB::table('bank_statements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($statement, 404);
            if ($statement->from_date !== $range->from_date || $statement->to_date !== $range->to_date) {
                throw new \DomainException('bank_evidence_period_range_changed');
            }
            $account = $this->account((int) $statement->treasury_account_id);
            abort_if((int) $statement->imported_by === (int) $actor->id, 403, 'يلزم مراجع مستقل عن مدخل الكشف.');
            abort_unless($this->intact($statement, DB::table('bank_statement_lines')->where('bank_statement_id', $id)->get()),
                409, 'بيانات الكشف المسجل لا تطابق بصمته الأصلية.');
            $line = DB::table('bank_statement_lines')->where('bank_statement_id', $id)->where('id', $lineId)->lockForUpdate()->first();
            $posting = DB::table('treasury_postings')->where('id', $postingId)->lockForUpdate()->first();
            abort_unless($line && $posting, 404);
            $record = ['bank_statement_line_id' => $lineId, 'treasury_posting_id' => $postingId,
                'matched_by' => $actor->id, 'evidence' => $evidence];
            if (! $this->valid($statement, $line, $posting, (object) $record)) {
                $this->reject('posting_id', 'الحركة لا تطابق الحساب والفترة والمبلغ والاتجاه.');
            }
            $existing = DB::table('bank_statement_matches')->where('bank_statement_line_id', $lineId)->first();
            if ($existing) {
                foreach ($record as $field => $value) {
                    abort_unless((string) $existing->$field === (string) $value, 409, 'سبق تسجيل مطابقة مختلفة.');
                }
                return $this->report($statement, $account);
            }
            abort_if(DB::table('bank_statement_matches')->where('treasury_posting_id', $postingId)->exists(),
                409, 'قيد الخزينة مطابق مسبقاً.');
            $matchId = DB::table('bank_statement_matches')->insertGetId($record + ['created_at' => now(), 'updated_at' => now()]);
            app(TreasuryRegisterService::class)->requiredAudit('bank_statement.matched', [
                'entity_type' => 'bank_statement_match', 'entity_id' => $matchId,
                'actor_user_id' => $actor->id, 'after' => $record,
            ]);
            return $this->report($statement, $account);
        });
    }

    /** Verify the independent fee document, its original bank line and exact posting/match. */
    private function validFeePosting(object $posting, object $statement, object $line, object $match, object $fee): bool
    {
        return $posting->kind === 'bank_fee'
            && ! $posting->cash_handover_id && ! $posting->payment_refund_id && ! $posting->cash_session_funding_id
            && ! $posting->treasury_transfer_id && ! $posting->treasury_transfer_receipt_id && ! $posting->treasury_cash_adjustment_id && ! $posting->treasury_bank_collection_id && ! $posting->treasury_late_cash_receipt_id
            && (int) $posting->treasury_bank_fee_id === (int) $fee->id
            && (int) $fee->bank_statement_line_id === (int) $line->id
            && (int) $line->bank_statement_id === (int) $statement->id
            && (int) $line->treasury_account_id === (int) $statement->treasury_account_id
            && (int) $fee->treasury_account_id === (int) $statement->treasury_account_id
            && (int) $posting->treasury_account_id === (int) $fee->treasury_account_id
            && TreasuryMoney::document($fee->amount_minor, true) > 0 && $this->bankMinor($line->amount_minor) === SignedMinor::negate(TreasuryMoney::document($fee->amount_minor, true))
            && SignedMinor::parse($posting->amount_minor) === $this->bankMinor($line->amount_minor)
            && $posting->effective_on === $line->booked_on && $fee->charged_on === $line->booked_on
            && $line->booked_on >= $statement->from_date && $line->booked_on <= $statement->to_date
            && $posting->evidence_reference === $fee->reference
            && (int) $posting->posted_by === (int) $fee->approved_by
            && (int) $match->matched_by === (int) $fee->approved_by
            && (int) $match->treasury_posting_id === (int) $posting->id
            && (int) $match->bank_statement_line_id === (int) $line->id
            && (int) $fee->approved_by !== (int) $statement->imported_by
            && $match->evidence === $fee->evidence && mb_strlen(trim($fee->evidence)) >= 10
            && mb_strlen(trim($fee->reason)) >= 10;
    }

    public function checkFeePosting(object $posting): bool
    {
        try {
            return $this->checkFeePostingExact($posting);
        } catch (\InvalidArgumentException|\OverflowException) {
            return false;
        }
    }

    private function checkFeePostingExact(object $posting): bool
    {
        $fee = $posting->treasury_bank_fee_id ? DB::table('treasury_bank_fees')->find($posting->treasury_bank_fee_id) : null;
        if (! $fee) return false;
        $line = DB::table('bank_statement_lines')->find($fee->bank_statement_line_id);
        $statement = $line ? DB::table('bank_statements')->find($line->bank_statement_id) : null;
        $account = DB::table('treasury_accounts')->find($fee->treasury_account_id);
        $match = DB::table('bank_statement_matches')->where('bank_statement_line_id', $fee->bank_statement_line_id)->first();
        return $line && $statement && $account && $match && $account->channel === 'bank'
            && $line->booked_on >= $account->opening_on && $line->booked_on <= now()->toDateString()
            && $this->intact($statement, DB::table('bank_statement_lines')->where('bank_statement_id', $statement->id)->get())
            && $this->validFeePosting($posting, $statement, $line, $match, $fee);
    }

    /** A bank charge is an already executed outflow, never a balancing plug. */
    public function recordFee(User $actor, int $id, int $lineId, string $reference, string $reason, string $evidence, ?GatedBankFeeWriter $gatedWriter = null): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $reference = mb_strtoupper(trim($reference));
        $reason = trim($reason);
        $evidence = trim($evidence);
        if (mb_strlen($reference) < 3 || mb_strlen($reference) > 150
            || mb_strlen($reason) < 10 || mb_strlen($reason) > 500
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000) {
            $this->reject('fee', 'يلزم مرجع فريد وسبب مستقل ودليل على رسوم البنك الفعلية.');
        }

        return DB::transaction(function () use ($actor, $id, $lineId, $reference, $reason, $evidence, $gatedWriter): array {
            $this->lock();
            $statement = DB::table('bank_statements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($statement, 404);
            $account = $this->account((int) $statement->treasury_account_id);
            abort_if((int) $statement->imported_by === (int) $actor->id, 403, 'يلزم مراجع مستقل عن مدخل الكشف.');
            abort_unless($this->intact($statement, DB::table('bank_statement_lines')->where('bank_statement_id', $id)->get()),
                409, 'بيانات الكشف لا تطابق بصمتها الأصلية.');
            $line = DB::table('bank_statement_lines')->where('bank_statement_id', $id)->where('id', $lineId)->lockForUpdate()->first();
            abort_unless($line, 404);
            if ($this->bankMinor($line->amount_minor) >= 0 || $line->booked_on < $account->opening_on) {
                $this->reject('line_id', 'الرسوم تتطلب حركة خصم بنكية فعلية بعد الافتتاح.');
            }
            $this->date($line->booked_on);
            $record = [
                'treasury_account_id' => (int) $account->id, 'bank_statement_line_id' => $lineId,
                'amount_minor' => SignedMinor::negate($this->bankMinor($line->amount_minor)), 'charged_on' => $line->booked_on,
                'reference' => $reference, 'reason' => $reason, 'evidence' => $evidence, 'approved_by' => $actor->id,
            ];
            $existing = DB::table('treasury_bank_fees')->where('bank_statement_line_id', $lineId)->lockForUpdate()->first();
            $postingId = $existing === null ? null : DB::table('treasury_postings')->where('treasury_bank_fee_id', $existing->id)->value('id');
            $registered = $postingId !== null && DB::table('financial_posting_events')
                ->where('scope_key', 'water')->where('source_type', 'treasury_posting')->where('leg', 'treasury')
                ->where('source_id', (string) $postingId)->exists();
            if (! ($gatedWriter !== null && $gatedWriter->allowsMutation($id, $lineId, (int) $actor->id))
                && ($gatedWriter !== null || $registered || config('financial_candidate.bank_fees') === true)) {
                throw new \DomainException('gated_bank_fee_writer_required');
            }
            if ($existing) {
                foreach ($record as $field => $value) {
                    abort_unless((string) $existing->$field === (string) $value, 409, 'سبق تسجيل رسوم مختلفة لهذه الحركة.');
                }
                $posting = DB::table('treasury_postings')->where('treasury_bank_fee_id', $existing->id)->first();
                abort_unless($posting && $this->checkFeePosting($posting), 409, 'قيد الرسوم السابق غير متطابق.');
                return ['fee' => (array) $existing, 'posting' => (array) $posting, 'reconciliation' => $this->report($statement, $account)];
            }
            abort_if(DB::table('bank_statement_matches')->where('bank_statement_line_id', $lineId)->exists(),
                409, 'الحركة البنكية مطابقة بالفعل.');
            abort_if(DB::table('treasury_bank_fees')->where('reference', $reference)->exists()
                || DB::table('treasury_accounts')->where('opening_reference', $reference)->exists()
                || DB::table('treasury_postings')->where('evidence_reference', $reference)->exists()
                || DB::table('cash_handovers')->where('reference', $reference)->exists()
                || DB::table('payment_refunds')->where('disbursement_reference', $reference)->exists()
                || DB::table('late_receipt_reviews')->where('evidence_reference', $reference)->exists(),
                409, 'مرجع الإثبات مستخدم مسبقاً.');
            // Be conservative across statement boundaries: a matching unallocated debit
            // could be the same transfer/refund with different bank booking date.
            abort_if(DB::table('treasury_postings')->where('treasury_account_id', $account->id)
                ->where('amount_minor', $line->amount_minor)
                ->whereNotIn('id', DB::table('bank_statement_matches')->select('treasury_posting_id'))->exists(),
                409, 'توجد حركة خصم غير مطابقة بالمبلغ نفسه؛ يجب مراجعتها قبل تسجيل رسوم جديدة.');

            $feeId = DB::table('treasury_bank_fees')->insertGetId($record + ['created_at' => now(), 'updated_at' => now()]);
            $postingId = DB::table('treasury_postings')->insertGetId([
                'treasury_account_id' => $account->id, 'treasury_bank_fee_id' => $feeId,
                'kind' => 'bank_fee', 'amount_minor' => $this->bankMinor($line->amount_minor), 'effective_on' => $line->booked_on,
                'evidence_reference' => $reference, 'posted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $matchId = DB::table('bank_statement_matches')->insertGetId([
                'bank_statement_line_id' => $lineId, 'treasury_posting_id' => $postingId,
                'matched_by' => $actor->id, 'evidence' => $evidence, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $posting = DB::table('treasury_postings')->find($postingId);
            if (! $this->checkFeePosting($posting)) throw new \LogicException('Bank fee posting is inconsistent.');
            app(TreasuryRegisterService::class)->requiredAudit('treasury.bank_fee_recorded', [
                'entity_type' => 'treasury_bank_fee', 'entity_id' => $feeId, 'actor_user_id' => $actor->id,
                'after' => $record + ['posting_id' => $postingId, 'match_id' => $matchId, 'subscriber_posting_created' => false],
            ]);
            return ['fee' => (array) DB::table('treasury_bank_fees')->find($feeId), 'posting' => (array) $posting,
                'reconciliation' => $this->report($statement, $account)];
        }, 3);
    }

    private function eligibleCollection(Payment $payment): bool
    {
        try {
            return $this->eligibleCollectionExact($payment);
        } catch (\InvalidArgumentException|\OverflowException) {
            return false;
        }
    }

    private function eligibleCollectionExact(Payment $payment): bool
    {
        if (! in_array($payment->payment_method, ['bank_transfer', 'check'], true)
            || $payment->isVoided() || $payment->hasPendingVoidRequest()
            || $this->receiptMinor($payment->getRawOriginal('amount')) <= 0
            || trim((string) $payment->reference_number) === ''
            || DB::table('closed_cash_corrections')->where('payment_id', $payment->id)->exists()
            || \App\Models\PaymentAccountTransfer::where('payment_id', $payment->id)->exists()) return false;
        $late = DB::table('late_receipt_reviews')->where('payment_id', $payment->id)->first();
        if ($late && ($late->status !== 'approved' || $late->settlement_method !== 'verified_non_cash'
            || (int) $late->subscriber_id !== (int) $payment->subscriber_id
            || $this->receiptMinor($late->amount) !== $this->receiptMinor($payment->getRawOriginal('amount'))
            || $late->payment_method !== $payment->payment_method)) return false;
        $entries = Transaction::where('reference_type', Payment::class)->where('reference_id', $payment->id)
            ->where('type', 'payment')->get();
        return $entries->count() === 1
            && (int) $entries->first()->subscriber_id === (int) $payment->subscriber_id
            && $this->receiptMinor($entries->first()->getRawOriginal('amount')) === $this->receiptMinor($payment->getRawOriginal('amount'))
            && (int) ($entries->first()->meter_id ?? 0) === (int) ($payment->meter_id ?? 0)
            && ! Transaction::where('reference_type', Payment::class)->where('reference_id', $payment->id)
                ->where('type', 'payment_reversal')->exists();
    }

    private function validLateCollectionLink(Payment $payment, object $doc, object $line): bool
    {
        $review = DB::table('late_receipt_reviews')->where('payment_id', $payment->id)->first();
        if (! $review || $review->bank_clearance_reference === null) return true;
        $session = \App\Models\CashSession::find($review->cash_session_id);
        return $session && $session->isApproved() && (int) $session->user_id === (int) $review->collector_id
            && (int) $review->collector_id === (int) $payment->user_id
            && (int) $review->decided_by === (int) $doc->approved_by
            && $review->bank_clearance_reference === $doc->reference && $review->evidence === $doc->evidence
            && $review->evidence_reference !== $review->bank_clearance_reference
            && $review->sync_uuid === $payment->sync_uuid && $review->reference_number === $payment->reference_number
            && (int) ($review->invoice_id ?? 0) === (int) ($payment->invoice_id ?? 0)
            && ($review->reason ?? '') === ($payment->reason ?? '')
            && $payment->cash_session_id === null && $payment->payment_date->toDateString() === $line->booked_on
            && $review->payment_date <= $line->booked_on;
    }

    private function validClaimCollectionLink(Payment $payment, object $doc, object $line): bool
    {
        $claims = DB::table('noncash_collection_claims')
            ->where(function ($query) use ($payment): void {
                $query->where('payment_id', $payment->id);
                if ($payment->sync_uuid) {
                    $query->orWhere('sync_uuid', $payment->sync_uuid);
                }
            })->get();
        // Historical receipts may predate the claim workflow.
        if ($claims->isEmpty()) return true;
        if ($claims->count() !== 1) return false;
        $claim = $claims->first();

        return $claim->status === 'cleared' && $claim->decided_at !== null
            && (int) $claim->payment_id === (int) $payment->id
            && $claim->sync_uuid === $payment->sync_uuid
            && (int) $claim->subscriber_id === (int) $payment->subscriber_id
            && (int) ($claim->invoice_id ?? 0) === (int) ($payment->invoice_id ?? 0)
            && (int) $claim->submitted_by === (int) $payment->user_id
            && (int) $claim->decided_by === (int) $doc->approved_by
            && (int) $claim->submitted_by !== (int) $claim->decided_by
            && $claim->payment_method === $payment->payment_method
            && $claim->instrument_reference === $payment->reference_number
            && MoneyBoundary::readMinor($claim->amount_minor, true, DecimalDomain::D12) === MoneyBoundary::readMinor($doc->amount_minor, true, DecimalDomain::D12)
            && $claim->instrument_on <= $line->booked_on
            && $payment->payment_date->toDateString() === $line->booked_on
            && $payment->cash_session_id === null
            && $claim->decision_evidence === $doc->evidence;
    }

    /** Audit the bank leg independently of the original subscriber credit. */
    public function checkCollectionPosting(object $posting): bool
    {
        try {
            return $this->checkCollectionPostingExact($posting);
        } catch (\InvalidArgumentException|\OverflowException) {
            return false;
        }
    }

    private function checkCollectionPostingExact(object $posting): bool
    {
        if ($posting->kind !== 'bank_collection' || ! $posting->treasury_bank_collection_id
            || $posting->cash_handover_id || $posting->payment_refund_id || $posting->cash_session_funding_id
            || $posting->treasury_transfer_id || $posting->treasury_transfer_receipt_id
            || $posting->treasury_bank_fee_id || $posting->treasury_cash_adjustment_id || $posting->treasury_late_cash_receipt_id) return false;
        $doc = DB::table('treasury_bank_collections')->find($posting->treasury_bank_collection_id);
        $payment = $doc ? Payment::find($doc->payment_id) : null;
        $line = $doc ? DB::table('bank_statement_lines')->find($doc->bank_statement_line_id) : null;
        $statement = $line ? DB::table('bank_statements')->find($line->bank_statement_id) : null;
        $account = $doc ? DB::table('treasury_accounts')->find($doc->treasury_account_id) : null;
        $match = $line ? DB::table('bank_statement_matches')->where('bank_statement_line_id', $line->id)->first() : null;
        return $doc && $payment && $line && $statement && $account && $match && $account->channel === 'bank'
            && $this->eligibleCollection($payment)
            && $this->validLateCollectionLink($payment, $doc, $line)
            && $this->validClaimCollectionLink($payment, $doc, $line)
            && $this->intact($statement, DB::table('bank_statement_lines')->where('bank_statement_id', $statement->id)->get())
            && (int) $statement->treasury_account_id === (int) $account->id
            && (int) $line->treasury_account_id === (int) $account->id
            && (int) $posting->treasury_account_id === (int) $account->id
            && (int) $doc->subscriber_id === (int) $payment->subscriber_id
            && $doc->payment_method === $payment->payment_method && $doc->receipt_number === $payment->receipt_number
            && $doc->payment_reference === $payment->reference_number
            && MoneyBoundary::readMinor($doc->amount_minor, true, DecimalDomain::D12) > 0 && MoneyBoundary::readMinor($doc->amount_minor, true, DecimalDomain::D12) === $this->receiptMinor($payment->getRawOriginal('amount'))
            && $this->bankMinor($line->amount_minor) === MoneyBoundary::readMinor($doc->amount_minor, true, DecimalDomain::D12) && SignedMinor::parse($posting->amount_minor) === MoneyBoundary::readMinor($doc->amount_minor, true, DecimalDomain::D12)
            && $doc->cleared_on === $line->booked_on && $posting->effective_on === $line->booked_on
            && $line->booked_on >= $account->opening_on && $line->booked_on <= today()->toDateString()
            && (int) $doc->approved_by !== (int) $statement->imported_by
            && (int) $doc->approved_by !== (int) $payment->user_id
            && (int) $posting->posted_by === (int) $doc->approved_by && (int) $match->matched_by === (int) $doc->approved_by
            && (int) $match->treasury_posting_id === (int) $posting->id
            && $posting->evidence_reference === $doc->reference && $match->evidence === $doc->evidence
            && mb_strlen(trim($doc->evidence)) >= 10;
    }

    /** Records full bank clearance of a previously posted receipt, never another subscriber credit. */
    /** New bank confirmations belong to the same scoped transaction as the subscriber credit. */
    private function assertCollectionWriter(Payment $payment, ?GatedCollectionWriter $writer): void
    {
        $uuid = $payment->sync_uuid;
        if ($writer !== null && is_string($uuid)) {
            $claim = DB::table('noncash_collection_claims')->where('payment_id', $payment->id)
                ->where('sync_uuid', $uuid)->lockForUpdate()->first();
            $late = DB::table('late_receipt_reviews')->where('payment_id', $payment->id)
                ->where('sync_uuid', $uuid)->lockForUpdate()->first();
            if ($claim !== null && $late === null && $claim->status === 'cleared'
                && $writer->allowsMutation('claim', (int) $claim->id, $uuid)) return;
            if ($late !== null && $claim === null && $late->status === 'approved'
                && $late->settlement_method === 'verified_non_cash'
                && $writer->allowsMutation('late_bank', (int) $late->id, $uuid)) return;
        }
        $registered = is_string($uuid) && DB::table('financial_posting_operations')
            ->where('scope_key', 'water')->where('operation_uuid', $uuid)->exists();
        if ($writer !== null || $registered || config('financial_candidate.noncash_settlements') === true) {
            throw new \DomainException('gated_bank_collection_writer_required');
        }
    }

    public function confirmCollection(User $actor, int $id, int $lineId, int $paymentId, string $reference, string $evidence, ?GatedCollectionWriter $gatedWriter = null): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        if (mb_strlen($reference) < 3 || mb_strlen($reference) > 150 || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000) {
            $this->reject('collection', 'يلزم مرجع فريد وإثبات مستقل لوصول المبلغ.');
        }
        $candidate = Payment::findOrFail($paymentId);
        return DB::transaction(function () use ($actor, $id, $lineId, $paymentId, $reference, $evidence, $candidate, $gatedWriter): array {
            // Issuance -> subscriber -> payment -> statement/account. The shared
            // fence serializes these writers before any business-resource lock.
            $this->lock();
            Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
            abort_unless((int) $payment->subscriber_id === (int) $candidate->subscriber_id, 409);
            $this->assertCollectionWriter($payment, $gatedWriter);
            $statement = DB::table('bank_statements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($statement, 404);
            $account = $this->account((int) $statement->treasury_account_id);
            abort_if((int) $statement->imported_by === (int) $actor->id || (int) $payment->user_id === (int) $actor->id,
                403, 'يلزم اعتماد مستقل عن مسجل السند ومدخل الكشف.');
            abort_unless($this->intact($statement, DB::table('bank_statement_lines')->where('bank_statement_id', $id)->get()),
                409, 'بيانات الكشف لا تطابق بصمتها الأصلية.');
            $line = DB::table('bank_statement_lines')->where('bank_statement_id', $id)->where('id', $lineId)->lockForUpdate()->first();
            abort_unless($line, 404);
            if (! $this->eligibleCollection($payment) || $this->bankMinor($line->amount_minor) <= 0
                || $this->bankMinor($line->amount_minor) !== $this->receiptMinor($payment->getRawOriginal('amount'))
                || $line->booked_on < $account->opening_on) {
                $this->reject('payment_id', 'يلزم سند غير نقدي سارٍ بمرجع وقيد مطابق ومبلغ إيداع كامل مثبت.');
            }
            $this->date($line->booked_on);
            $record = [
                'treasury_account_id' => (int) $account->id, 'bank_statement_line_id' => $lineId,
                'payment_id' => $paymentId, 'subscriber_id' => $payment->subscriber_id,
                'receipt_number' => $payment->receipt_number, 'payment_reference' => $payment->reference_number,
                'payment_method' => $payment->payment_method, 'amount_minor' => $this->bankMinor($line->amount_minor),
                'cleared_on' => $line->booked_on, 'reference' => $reference, 'evidence' => $evidence, 'approved_by' => $actor->id,
            ];
            $existing = DB::table('treasury_bank_collections')->where('payment_id', $paymentId)->lockForUpdate()->first();
            if ($existing) {
                foreach ($record as $field => $value) {
                    abort_unless((string) $existing->$field === (string) $value, 409, 'سبق إثبات وصول هذا السند بصورة مختلفة.');
                }
                $posting = DB::table('treasury_postings')->where('treasury_bank_collection_id', $existing->id)->first();
                abort_unless($posting && $this->checkCollectionPosting($posting), 409, 'إثبات التحصيل السابق غير متطابق.');
                return ['collection' => (array) $existing, 'posting' => (array) $posting, 'reconciliation' => $this->report($statement, $account)];
            }
            abort_if(DB::table('treasury_bank_collections')->where('bank_statement_line_id', $lineId)->exists()
                || DB::table('bank_statement_matches')->where('bank_statement_line_id', $lineId)->exists(),
                409, 'حركة البنك مستخدمة بالفعل.');
            abort_if(DB::table('treasury_bank_collections')->where('reference', $reference)->exists()
                || DB::table('treasury_accounts')->where('opening_reference', $reference)->exists()
                || DB::table('treasury_postings')->where('evidence_reference', $reference)->exists()
                || DB::table('cash_handovers')->where('reference', $reference)->exists()
                || DB::table('payment_refunds')->where('disbursement_reference', $reference)->exists()
                || DB::table('late_receipt_reviews')->where('evidence_reference', $reference)->exists(),
                409, 'مرجع الإثبات مستخدم مسبقاً.');
            abort_if(DB::table('treasury_postings')->where('treasury_account_id', $account->id)
                ->where('amount_minor', $line->amount_minor)
                ->whereNotIn('id', DB::table('bank_statement_matches')->select('treasury_posting_id'))->exists(),
                409, 'يوجد إيداع غير مطابق بالقيمة نفسها؛ يلزم مراجعة احتمال الازدواجية.');
            $collectionId = DB::table('treasury_bank_collections')->insertGetId($record + ['created_at' => now(), 'updated_at' => now()]);
            $postingId = DB::table('treasury_postings')->insertGetId([
                'treasury_account_id' => $account->id, 'treasury_bank_collection_id' => $collectionId,
                'kind' => 'bank_collection', 'amount_minor' => $this->bankMinor($line->amount_minor), 'effective_on' => $line->booked_on,
                'evidence_reference' => $reference, 'posted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $matchId = DB::table('bank_statement_matches')->insertGetId([
                'bank_statement_line_id' => $lineId, 'treasury_posting_id' => $postingId,
                'matched_by' => $actor->id, 'evidence' => $evidence, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $posting = DB::table('treasury_postings')->find($postingId);
            if (! $this->checkCollectionPosting($posting)) throw new \LogicException('Bank collection proof inconsistent.');
            app(TreasuryRegisterService::class)->requiredAudit('treasury.bank_collection_confirmed', [
                'entity_type' => 'treasury_bank_collection', 'entity_id' => $collectionId, 'actor_user_id' => $actor->id,
                'after' => $record + ['posting_id' => $postingId, 'match_id' => $matchId, 'subscriber_posting_created' => false],
            ]);
            return ['collection' => (array) DB::table('treasury_bank_collections')->find($collectionId),
                'posting' => (array) $posting, 'reconciliation' => $this->report($statement, $account)];
        }, 3);
    }

    private function report(object $statement, object $account): array
    {
        $id = $statement->id;
        $from = $statement->from_date;
        $to = $statement->to_date;
        $ledger = DB::table('treasury_postings')->where('treasury_account_id', $account->id);
        $opening = $this->sumRows((clone $ledger)->where('effective_on', '<', $from)->orderBy('effective_on')->orderBy('id')->get(['amount_minor']), false, TreasuryMoney::document($account->opening_minor));
        $postings = (clone $ledger)->whereBetween('effective_on', [$from, $to])->orderBy('effective_on')->orderBy('id')->get()->keyBy('id');
        $lines = DB::table('bank_statement_lines')->where('bank_statement_id', $id)->orderBy('booked_on')->orderBy('id')->get()->keyBy('id');
        $matches = DB::table('bank_statement_matches')->whereIn('bank_statement_line_id',
            DB::table('bank_statement_lines')->where('bank_statement_id', $id)->select('id'))->get();
        $matchedLines = [];
        $matchedPostings = [];
        $intact = $this->intact($statement, $lines);
        $issues = $intact ? [] : [['code' => 'statement_content_mismatch']];
        foreach ($matches as $match) {
            $line = $lines->get($match->bank_statement_line_id);
            $posting = $postings->get($match->treasury_posting_id);
            if (! $line || ! $this->valid($statement, $line, $posting, $match)) {
                $issues[] = ['code' => 'invalid_match', 'match_id' => $match->id];
                continue;
            }
            $matchedLines[$line->id] = true;
            $matchedPostings[$posting->id] = true;
        }
        $bankOnly = $lines->reject(fn ($line) => isset($matchedLines[$line->id]))->values();
        $ledgerOnly = $postings->reject(fn ($posting) => isset($matchedPostings[$posting->id]))->values();
        $closing = $this->sumRows($postings, false, $opening);
        $bankClosing = $this->bankMinor($statement->closing_minor);
        $openingDifference = SignedMinor::subtract($this->bankMinor($statement->opening_minor), $opening);
        $difference = SignedMinor::subtract($bankClosing, $closing);
        $bankUnmatched = $this->sumRows($bankOnly, true);
        $ledgerUnmatched = $this->sumRows($ledgerOnly);
        if ($this->sumRows($lines, true, $this->bankMinor($statement->opening_minor)) !== $bankClosing) {
            $issues[] = ['code' => 'statement_total_mismatch'];
        }
        if ($difference !== SignedMinor::subtract(SignedMinor::add($openingDifference, $bankUnmatched), $ledgerUnmatched)) {
            $issues[] = ['code' => 'reconciliation_identity_mismatch'];
        }
        return [
            'statement' => (array) $statement,
            'statement_integrity_matches' => $intact,
            'recorded_opening_minor' => $opening, 'recorded_closing_minor' => $closing,
            'opening_difference_minor' => $openingDifference, 'closing_difference_minor' => $difference,
            'bank_unmatched_minor' => $bankUnmatched, 'ledger_unmatched_minor' => $ledgerUnmatched,
            'matched_count' => count($matchedLines),
            'unmatched_bank_lines' => $bankOnly->all(), 'unmatched_ledger_postings' => $ledgerOnly->all(),
            'matches' => $matches->all(), 'issues' => $issues,
            'fully_matched' => $issues === [] && $bankOnly->isEmpty() && $ledgerOnly->isEmpty()
                && $openingDifference === 0 && $difference === 0,
            'certified_balance' => false,
        ];
    }

    public function inspect(User $actor, int $id): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        return DB::transaction(function () use ($id): array {
            $this->lock();
            $statement = DB::table('bank_statements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($statement, 404);
            return $this->report($statement, $this->account((int) $statement->treasury_account_id));
        }, 3);
    }

    public function index(User $actor, int $accountId): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        return DB::transaction(function () use ($accountId): array {
            $this->lock();
            $this->account($accountId);
            return ['statements' => DB::table('bank_statements')->where('treasury_account_id', $accountId)
                ->orderByDesc('from_date')->get(), 'certified_balance' => false];
        }, 3);
    }
}
