<?php

namespace App\Services;

use App\Models\CashHandover;
use App\Models\CashSession;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Initial cash/bank evidence register, not a certified treasury balance. */
final class TreasuryRegisterService
{
    public function authorize(User $actor): void
    {
        abort_unless($actor->hasRole('super_admin'), 403);
    }

    private function lockRegister(): void
    {
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new \LogicException('Financial issuance lock is missing.');
        }
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** Parse DECIMAL strings without binary floating point or permissive rounding. */
    private function minor(string $amount): int
    {
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/D', $amount, $parts)) {
            $this->reject('amount', 'المبلغ غير صالح أو يتجاوز منزلتين عشريتين.');
        }
        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    private function date(string $date): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            || $date > now()->toDateString()) {
            $this->reject('date', 'تاريخ المستند غير صالح أو مستقبلي.');
        }
    }

    public function open(User $actor, array $data): array
    {
        $this->authorize($actor);
        $code = mb_strtoupper(trim($data['code']));
        $name = trim($data['name']);
        $reference = mb_strtoupper(trim($data['opening_reference']));
        $evidence = trim($data['opening_evidence']);
        $opening = $this->minor((string) $data['opening_amount']);
        $this->date($data['opening_on']);
        if (! preg_match('/^[A-Z0-9_-]{2,50}$/D', $code)
            || mb_strlen($name) < 2 || mb_strlen($name) > 150
            || ! in_array($data['channel'], ['cash', 'bank'], true)
            || mb_strlen($reference) < 3 || mb_strlen($reference) > 150
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000) {
            $this->reject('opening', 'يلزم تعريف الحساب ورصيد افتتاح مثبت قبل بداية اليوم المحدد.');
        }
        $record = [
            'code' => $code, 'name' => $name, 'channel' => $data['channel'],
            'opening_on' => $data['opening_on'], 'opening_minor' => $opening,
            'opening_reference' => $reference, 'opening_evidence' => $evidence, 'opened_by' => $actor->id,
        ];
        return DB::transaction(function () use ($record, $actor): array {
            $this->lockRegister();
            $existing = DB::table('treasury_accounts')->where('code', $record['code'])->lockForUpdate()->first();
            if ($existing) {
                foreach ($record as $field => $value) {
                    abort_unless((string) $existing->$field === (string) $value, 409, 'رمز الخزينة مستخدم بالفعل.');
                }
                return (array) $existing;
            }
            abort_if(DB::table('treasury_accounts')->where('opening_reference', $record['opening_reference'])->exists()
                || DB::table('treasury_postings')->where('evidence_reference', $record['opening_reference'])->exists(),
                409, 'مرجع الافتتاح مستخدم مسبقاً.');
            $id = DB::table('treasury_accounts')->insertGetId($record + ['created_at' => now(), 'updated_at' => now()]);
            app(AuditLogger::class)->recordRequired('treasury.opened', [
                'entity_type' => 'treasury_account', 'entity_id' => $id, 'actor_user_id' => $actor->id, 'after' => $record,
            ]);
            return (array) DB::table('treasury_accounts')->find($id);
        }, 3);
    }

    public function post(User $actor, int $accountId, string $kind, int $sourceId): array
    {
        $this->authorize($actor);
        if (! in_array($kind, ['cash_handover', 'payment_refund'], true)) {
            $this->reject('kind', 'مصدر الحركة غير مدعوم في هذه المرحلة.');
        }
        return DB::transaction(function () use ($actor, $accountId, $kind, $sourceId): array {
            $this->lockRegister();
            $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
            abort_unless($account, 404);
            $handover = $kind === 'cash_handover';
            $source = $handover
                ? CashHandover::query()->lockForUpdate()->findOrFail($sourceId)
                : PaymentRefund::query()->lockForUpdate()->findOrFail($sourceId);
            $amount = $this->minor((string) $source->amount);
            if ($handover) {
                $channel = 'cash';
                $date = $source->received_on->toDateString();
                $reference = $source->reference;
                $session = CashSession::query()->lockForUpdate()->findOrFail($source->cash_session_id);
                $summary = app(CashHandoverService::class)->summary($session);
                if (! in_array($summary['status'], ['partial', 'complete'], true)
                    || (int) $session->user_id === (int) $source->received_by
                    || $date < $session->closed_at->toDateString()) {
                    $this->reject('source', 'تسليم الصندوق غير متسق مع التسوية الأصلية.');
                }
            } else {
                $channel = match ($source->method) {
                    'treasury_cash' => 'cash',
                    'bank_transfer' => 'bank',
                    default => null,
                };
                $date = $source->disbursed_on->toDateString();
                $reference = $source->disbursement_reference;
                $amount = -$amount;
            }
            $this->date($date);
            $reference = mb_strtoupper(trim($reference));
            if ($amount === 0 || $channel !== $account->channel || $date < $account->opening_on
                || mb_strlen($reference) < 3 || mb_strlen(trim($source->evidence)) < 10) {
                $this->reject('source', 'الحركة لا تتوافق مع وسيلة الخزينة أو تاريخ افتتاحها أو دليلها.');
            }
            $column = $handover ? 'cash_handover_id' : 'payment_refund_id';
            $record = [
                'treasury_account_id' => $accountId,
                'cash_handover_id' => $handover ? $sourceId : null,
                'payment_refund_id' => $handover ? null : $sourceId,
                'kind' => $kind, 'amount_minor' => $amount, 'effective_on' => $date,
                'evidence_reference' => $reference,
            ];
            $existing = DB::table('treasury_postings')->where($column, $sourceId)->lockForUpdate()->first();
            if ($existing) {
                foreach ($record as $field => $value) {
                    abort_unless((string) $existing->$field === (string) $value, 409, 'المستند مرحل مسبقاً بصورة مختلفة.');
                }
                return (array) $existing;
            }
            abort_if(DB::table('treasury_accounts')->where('opening_reference', $reference)->exists()
                || DB::table('treasury_postings')->where('evidence_reference', $reference)->exists(),
                409, 'مرجع الإثبات مستخدم مسبقاً في الخزينة.');
            $id = DB::table('treasury_postings')->insertGetId($record + [
                'posted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(AuditLogger::class)->recordRequired('treasury.posted', [
                'entity_type' => 'treasury_posting', 'entity_id' => $id, 'actor_user_id' => $actor->id, 'after' => $record,
            ]);
            return (array) DB::table('treasury_postings')->find($id);
        }, 3);
    }

    public function inspect(User $actor, int $accountId): array
    {
        $this->authorize($actor);
        return DB::transaction(function () use ($accountId): array {
            $this->lockRegister();
            $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
            abort_unless($account, 404);
            $rows = DB::table('treasury_postings')->where('treasury_account_id', $accountId)
                ->orderBy('effective_on')->orderBy('id')->get();
            $balance = (int) $account->opening_minor;
            $incoming = 0;
            $outgoing = 0;
            $entries = [];
            $issues = [];
            foreach ($rows as $row) {
                $amount = (int) $row->amount_minor;
                $balance += $amount;
                $incoming += max(0, $amount);
                $outgoing += max(0, -$amount);
                $entries[] = (array) $row + ['running_balance_minor' => $balance];
                if ($balance < 0) $issues[] = ['code' => 'negative_recorded_subtotal', 'posting_id' => $row->id];
                // This register does not assert source integrity from the net amount alone.
                if ($row->kind === 'bank_collection') {
                    if (! app(BankReconciliationService::class)->checkCollectionPosting($row)) {
                        $issues[] = ['code' => 'bank_collection_source_mismatch', 'posting_id' => $row->id];
                    }
                    continue;
                }
                if ($row->treasury_bank_collection_id) {
                    $issues[] = ['code' => 'unexpected_bank_collection_source', 'posting_id' => $row->id];
                }
                if ($row->kind === 'cash_count_adjustment') {
                    if (! app(TreasuryCashCountService::class)->checkAdjustmentPosting($row)) {
                        $issues[] = ['code' => 'cash_adjustment_source_mismatch', 'posting_id' => $row->id];
                    }
                    continue;
                }
                if ($row->treasury_cash_adjustment_id) {
                    $issues[] = ['code' => 'unexpected_cash_adjustment_source', 'posting_id' => $row->id];
                }
                if ($row->kind === 'bank_fee') {
                    if (! app(BankReconciliationService::class)->checkFeePosting($row)) {
                        $issues[] = ['code' => 'bank_fee_source_mismatch', 'posting_id' => $row->id];
                    }
                    continue;
                }
                if ($row->treasury_bank_fee_id) {
                    $issues[] = ['code' => 'unexpected_bank_fee_source', 'posting_id' => $row->id];
                }
                if (in_array($row->kind, ['internal_transfer_out', 'internal_transfer_in'], true)) {
                    if (! app(TreasuryTransferService::class)->validPosting($row)) {
                        $issues[] = ['code' => 'transfer_source_mismatch', 'posting_id' => $row->id];
                    }
                    continue;
                }
                if ($row->treasury_transfer_id || $row->treasury_transfer_receipt_id) {
                    $issues[] = ['code' => 'unexpected_transfer_source', 'posting_id' => $row->id];
                }
                if ($row->kind === 'collector_funding') {
                    $funding = $row->cash_session_funding_id ? DB::table('cash_session_fundings')->find($row->cash_session_funding_id) : null;
                    $session = $funding ? CashSession::find($funding->cash_session_id) : null;
                    if (! $funding || ! $session || $row->cash_handover_id || $row->payment_refund_id
                        || $account->channel !== 'cash' || (int) $funding->treasury_account_id !== $accountId
                        || $amount !== -(int) $funding->amount_minor || $row->effective_on !== $funding->issued_on
                        || $row->effective_on < $account->opening_on || $row->evidence_reference !== $funding->reference
                        || app(CashSessionService::class)->fundingSummary($session)['status'] !== 'documented') {
                        $issues[] = ['code' => 'funding_source_mismatch', 'posting_id' => $row->id];
                    }
                    continue;
                }
                if ($row->cash_session_funding_id) {
                    $issues[] = ['code' => 'unexpected_funding_source', 'posting_id' => $row->id];
                }
                $source = $row->kind === 'cash_handover' && $row->cash_handover_id && ! $row->payment_refund_id
                    ? CashHandover::find($row->cash_handover_id)
                    : ($row->kind === 'payment_refund' && $row->payment_refund_id && ! $row->cash_handover_id
                        ? PaymentRefund::find($row->payment_refund_id) : null);
                $expected = $source instanceof CashHandover ? $this->minor((string) $source->amount)
                    : ($source instanceof PaymentRefund ? -$this->minor((string) $source->amount) : null);
                $sourceDate = $source instanceof CashHandover ? $source->received_on->toDateString()
                    : ($source instanceof PaymentRefund ? $source->disbursed_on->toDateString() : null);
                $sourceChannel = $source instanceof CashHandover ? 'cash'
                    : ($source instanceof PaymentRefund ? match ($source->method) {
                        'treasury_cash' => 'cash', 'bank_transfer' => 'bank', default => null,
                    } : null);
                $reference = $source instanceof CashHandover ? $source->reference : ($source?->disbursement_reference ?? '');
                if ($expected === null || $expected !== $amount || $sourceDate !== $row->effective_on
                    || $sourceChannel !== $account->channel || $row->effective_on < $account->opening_on
                    || $row->evidence_reference !== mb_strtoupper(trim($reference))) {
                    $issues[] = ['code' => 'source_mismatch', 'posting_id' => $row->id];
                }
            }
            $unpostedHandover = $account->channel === 'cash' ? DB::table('cash_handovers')
                ->where('received_on', '>=', $account->opening_on)
                ->whereNotIn('id', DB::table('treasury_postings')->whereNotNull('cash_handover_id')->select('cash_handover_id'))->count() : 0;
            $unpostedRefund = DB::table('payment_refunds')
                ->where('method', $account->channel === 'cash' ? 'treasury_cash' : 'bank_transfer')
                ->where('disbursed_on', '>=', $account->opening_on)
                ->whereNotIn('id', DB::table('treasury_postings')->whereNotNull('payment_refund_id')->select('payment_refund_id'))->count();
            $unpostedFunding = DB::table('cash_session_fundings')->where('treasury_account_id', $accountId)
                ->whereNotIn('id', DB::table('treasury_postings')->whereNotNull('cash_session_funding_id')->select('cash_session_funding_id'))->count();
            if ($unpostedFunding) $issues[] = ['code' => 'unposted_funding', 'count' => $unpostedFunding];
            $unpostedFees = DB::table('treasury_bank_fees')->where('treasury_account_id', $accountId)
                ->whereNotIn('id', DB::table('treasury_postings')->whereNotNull('treasury_bank_fee_id')->select('treasury_bank_fee_id'))->count();
            if ($unpostedFees) $issues[] = ['code' => 'unposted_bank_fee', 'count' => $unpostedFees];
            $unpostedAdjustments = DB::table('treasury_cash_adjustments')->where('treasury_account_id', $accountId)
                ->whereNotIn('id', DB::table('treasury_postings')->whereNotNull('treasury_cash_adjustment_id')->select('treasury_cash_adjustment_id'))->count();
            if ($unpostedAdjustments) $issues[] = ['code' => 'unposted_cash_adjustment', 'count' => $unpostedAdjustments];
            $held = (int) DB::table('treasury_cash_adjustments')->where('treasury_account_id', $accountId)
                ->where('classification', 'unattributed_cash_held')->sum('amount_minor');
            $unpostedCollections = DB::table('treasury_bank_collections')->where('treasury_account_id', $accountId)
                ->whereNotIn('id', DB::table('treasury_postings')->whereNotNull('treasury_bank_collection_id')->select('treasury_bank_collection_id'))->count();
            if ($unpostedCollections) $issues[] = ['code' => 'unposted_bank_collection', 'count' => $unpostedCollections];
            // Channel-wide, not an amount attributable to this particular account.
            $unconfirmed = $account->channel === 'bank' ? DB::table('payments')->whereNull('deleted_at')->whereNull('voided_at')
                ->whereIn('payment_method', ['bank_transfer', 'check'])
                ->whereNotIn('id', DB::table('treasury_bank_collections')->select('payment_id'))->count() : 0;
            $transfers = app(TreasuryTransferService::class)->inspectAccount($accountId);
            $issues = array_merge($issues, $transfers['issues']);
            return [
                'account' => (array) $account,
                'incoming_minor' => $incoming, 'outgoing_minor' => $outgoing,
                'provisional_recorded_balance_minor' => $balance,
                'certified_balance' => false,
                'coverage' => 'opening_handover_refund_funding_internal_transfers_bank_fees_cash_adjustments_bank_collections',
                'channel_unconfirmed_non_cash_count' => $unconfirmed,
                'unposted_bank_collection_count' => $unpostedCollections,
                'unposted_cash_adjustment_count' => $unpostedAdjustments,
                'unattributed_cash_held_minor' => $held,
                'unposted_bank_fee_count' => $unpostedFees,
                'internal_transfers' => $transfers,
                'unposted_funding_count' => $unpostedFunding,
                'channel_unposted_handover_count' => $unpostedHandover,
                'channel_unposted_refund_count' => $unpostedRefund,
                'issues' => $issues, 'entries' => $entries,
            ];
        }, 3);
    }
}
