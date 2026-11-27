<?php

namespace App\Services;

use App\Models\User;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\SignedMinor;
use App\Support\TreasuryMoney as Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Records verified internal custody movements; no revenue or subscriber posting. */
final class TreasuryTransferService
{
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

    private function evidence(array $data, string $dateKey): array
    {
        $reference = mb_strtoupper(trim($data['reference']));
        $evidence = trim($data['evidence']);
        $date = $data[$dateKey];
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/D', (string) $data['amount'])
            || mb_strlen($reference) < 3 || mb_strlen($reference) > 150
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            || $date > now()->toDateString()) {
            $this->reject('transfer', 'بيانات التحويل أو الإثبات غير صالحة.');
        }
        $minor = Cash::input($data['amount'], 'amount', DecimalDomain::D14, 1, digitsOnly: true);
        return ['amount_minor' => $minor, 'reference' => $reference, $dateKey => $date, 'evidence' => $evidence];
    }

    private function accounts(int $from, int $to): array
    {
        if ($from === $to) $this->reject('to_account_id', 'لا يجوز التحويل إلى الحساب نفسه.');
        $accounts = DB::table('treasury_accounts')->whereIn('id', [$from, $to])
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($accounts->count() === 2, 404);
        return [$accounts[$from], $accounts[$to]];
    }

    private function unusedReference(string $reference): void
    {
        abort_if(DB::table('treasury_accounts')->where('opening_reference', $reference)->exists()
            || DB::table('treasury_postings')->where('evidence_reference', $reference)->exists()
            || DB::table('treasury_transfers')->where('reference', $reference)->exists()
            || DB::table('treasury_transfer_receipts')->where('reference', $reference)->exists()
            || DB::table('cash_handovers')->where('reference', $reference)->exists()
            || DB::table('payment_refunds')->where('disbursement_reference', $reference)->exists()
            || DB::table('late_receipt_reviews')->where('evidence_reference', $reference)->exists(),
            409, 'مرجع الإثبات مستخدم مسبقاً.');
    }

    private function same(object $existing, array $record): void
    {
        foreach ($record as $key => $value) {
            abort_unless((string) $existing->$key === (string) $value, 409, 'مرجع التحويل مرتبط بعملية مختلفة.');
        }
    }

    private function result(object $transfer): array
    {
        $receipt = DB::table('treasury_transfer_receipts')->where('treasury_transfer_id', $transfer->id)->first();
        $issues = $this->issues($transfer, $receipt);
        return ['id' => $transfer->id, 'dispatch' => (array) $transfer,
            'receipt' => $receipt ? (array) $receipt : null,
            'status' => $issues ? 'inconsistent' : ($receipt ? 'received' : 'in_transit'),
            'in_transit_minor' => $issues ? null : ($receipt ? 0 : Money::document($transfer->amount_minor, true)),
            'issues' => $issues];
    }

    private function registered(string $column, int $id): bool
    {
        $ids = DB::table('treasury_postings')->where($column, $id)->pluck('id');
        foreach ($ids as $postingId) {
            if (DB::table('financial_posting_events')->where('scope_key', 'water')
                ->where('source_type', 'treasury_posting')->where('leg', 'treasury')
                ->where('source_id', (string) $postingId)->exists()) return true;
        }
        return false;
    }

    private function requireWriter(string $kind, string $identity, User $actor,
        ?GatedTreasuryTransferWriter $writer, bool $registered): void
    {
        if (! ($writer !== null && $writer->allowsMutation($kind, $identity, (int) $actor->id))
            && ($writer !== null || $registered || config('financial_candidate.treasury_transfers') === true)) {
            throw new \DomainException('gated_treasury_transfer_writer_required');
        }
    }

    public function dispatch(User $actor, array $data, ?GatedTreasuryTransferWriter $gatedWriter = null): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $record = $this->evidence($data, 'dispatched_on') + [
            'from_account_id' => (int) $data['from_account_id'], 'to_account_id' => (int) $data['to_account_id'],
            'dispatched_by' => $actor->id,
        ];
        return DB::transaction(function () use ($actor, $record, $gatedWriter): array {
            if ($gatedWriter === null && DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first()) {
                throw new \DomainException('gated_treasury_transfer_writer_required');
            }
            $this->lock();
            [$from, $to] = $this->accounts($record['from_account_id'], $record['to_account_id']);
            $existing = DB::table('treasury_transfers')->where('reference', $record['reference'])->lockForUpdate()->first();
            $this->requireWriter('dispatch', $record['reference'], $actor, $gatedWriter,
                $existing !== null && $this->registered('treasury_transfer_id', (int) $existing->id));
            if ($existing) {
                $this->same($existing, $record);
                $result = $this->result($existing);
                abort_if($result['issues'], 409, 'التحويل السابق غير متطابق؛ يلزم تدقيقه.');
                return $result;
            }
            if ($record['dispatched_on'] < $from->opening_on || $record['dispatched_on'] < $to->opening_on) {
                $this->reject('dispatched_on', 'يجب أن يسبق افتتاح الحسابين تاريخ التحويل أو يوافقه.');
            }
            app(HeldCashReservation::class)->assertOrdinaryOutflow((int) $from->id, $record['amount_minor'], $record['dispatched_on']);
            $this->unusedReference($record['reference']);
            $id = DB::table('treasury_transfers')->insertGetId($record + ['created_at' => now(), 'updated_at' => now()]);
            $posting = DB::table('treasury_postings')->insertGetId([
                'treasury_account_id' => $from->id, 'treasury_transfer_id' => $id,
                'kind' => 'internal_transfer_out', 'amount_minor' => SignedMinor::negate($record['amount_minor']),
                'effective_on' => $record['dispatched_on'], 'evidence_reference' => $record['reference'],
                'posted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(TreasuryRegisterService::class)->requiredAudit('treasury.transfer_dispatched', [
                'entity_type' => 'treasury_transfer', 'entity_id' => $id, 'actor_user_id' => $actor->id,
                'after' => $record + ['posting_id' => $posting, 'in_transit_minor' => $record['amount_minor']],
            ]);
            return $this->result(DB::table('treasury_transfers')->find($id));
        }, 3);
    }

    public function receive(User $actor, int $id, array $data, ?GatedTreasuryTransferWriter $gatedWriter = null): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $record = $this->evidence($data, 'received_on') + ['treasury_transfer_id' => $id, 'received_by' => $actor->id];
        return DB::transaction(function () use ($actor, $id, $record, $gatedWriter): array {
            if ($gatedWriter === null && DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first()) {
                throw new \DomainException('gated_treasury_transfer_writer_required');
            }
            $this->lock();
            $transfer = DB::table('treasury_transfers')->where('id', $id)->lockForUpdate()->first();
            abort_unless($transfer, 404);
            [$from, $to] = $this->accounts((int) $transfer->from_account_id, (int) $transfer->to_account_id);
            abort_if((int) $transfer->dispatched_by === (int) $actor->id, 403, 'يجب أن يثبت الاستلام مسؤول مستقل عن المرسل.');
            $existing = DB::table('treasury_transfer_receipts')->where('treasury_transfer_id', $id)->lockForUpdate()->first();
            $this->requireWriter('receive', (string) $id, $actor, $gatedWriter,
                $this->registered('treasury_transfer_id', $id)
                    || ($existing !== null && $this->registered('treasury_transfer_receipt_id', (int) $existing->id)));
            if ($existing) {
                $this->same($existing, $record);
                $result = $this->result($transfer);
                abort_if($result['issues'], 409, 'التحويل السابق غير متطابق؛ يلزم تدقيقه.');
                return $result;
            }
            abort_if($this->issues($transfer, null), 409, 'قيد الإرسال غير متطابق.');
            if ($record['amount_minor'] !== Money::document($transfer->amount_minor, true)
                || $record['received_on'] < $transfer->dispatched_on || $record['received_on'] < $to->opening_on) {
                $this->reject('amount', 'يلزم إثبات الاستلام الكامل المطابق؛ الفروق تحتاج تسوية مستقلة.');
            }
            $this->unusedReference($record['reference']);
            $receiptId = DB::table('treasury_transfer_receipts')->insertGetId($record + ['created_at' => now(), 'updated_at' => now()]);
            $posting = DB::table('treasury_postings')->insertGetId([
                'treasury_account_id' => $to->id, 'treasury_transfer_receipt_id' => $receiptId,
                'kind' => 'internal_transfer_in', 'amount_minor' => $record['amount_minor'],
                'effective_on' => $record['received_on'], 'evidence_reference' => $record['reference'],
                'posted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(TreasuryRegisterService::class)->requiredAudit('treasury.transfer_received', [
                'entity_type' => 'treasury_transfer_receipt', 'entity_id' => $receiptId, 'actor_user_id' => $actor->id,
                'after' => $record + ['posting_id' => $posting, 'in_transit_minor' => 0],
            ]);
            return $this->result($transfer);
        }, 3);
    }

    public function validPosting(object $row): bool
    {
        try {
            return $this->validPostingExact($row);
        } catch (\InvalidArgumentException|\OverflowException) {
            return false;
        }
    }

    private function validPostingExact(object $row): bool
    {
        if ($row->cash_handover_id || $row->payment_refund_id || $row->cash_session_funding_id || $row->treasury_bank_fee_id || $row->treasury_cash_adjustment_id || $row->treasury_bank_collection_id || $row->treasury_late_cash_receipt_id) return false;
        if ($row->kind === 'internal_transfer_out' && $row->treasury_transfer_id && ! $row->treasury_transfer_receipt_id) {
            $transfer = DB::table('treasury_transfers')->find($row->treasury_transfer_id);
            return $transfer && (int) $row->treasury_account_id === (int) $transfer->from_account_id
                && Money::document($transfer->amount_minor, true) > 0 && Money::signed($row->amount_minor) === SignedMinor::negate(Money::document($transfer->amount_minor, true))
                && $row->effective_on === $transfer->dispatched_on && $row->evidence_reference === $transfer->reference
                && (int) $row->posted_by === (int) $transfer->dispatched_by;
        }
        if ($row->kind === 'internal_transfer_in' && $row->treasury_transfer_receipt_id && ! $row->treasury_transfer_id) {
            $receipt = DB::table('treasury_transfer_receipts')->find($row->treasury_transfer_receipt_id);
            $transfer = $receipt ? DB::table('treasury_transfers')->find($receipt->treasury_transfer_id) : null;
            return $transfer && (int) $row->treasury_account_id === (int) $transfer->to_account_id
                && Money::document($receipt->amount_minor, true) === Money::document($transfer->amount_minor, true)
                && Money::signed($row->amount_minor) === Money::document($receipt->amount_minor, true)
                && $row->effective_on === $receipt->received_on && $row->evidence_reference === $receipt->reference
                && (int) $row->posted_by === (int) $receipt->received_by;
        }
        return false;
    }

    private function issues(object $transfer, ?object $receipt): array
    {
        $issues = [];
        $out = DB::table('treasury_postings')->where('treasury_transfer_id', $transfer->id)->first();
        if (! $out || ! $this->validPosting($out)) $issues[] = 'dispatch_posting_mismatch';
        $from = DB::table('treasury_accounts')->find($transfer->from_account_id);
        $to = DB::table('treasury_accounts')->find($transfer->to_account_id);
        if (! $from || ! $to || (int) $from->id === (int) $to->id || $transfer->dispatched_on < $from->opening_on
            || $transfer->dispatched_on < $to->opening_on) $issues[] = 'transfer_account_mismatch';
        if ($receipt) {
            $in = DB::table('treasury_postings')->where('treasury_transfer_receipt_id', $receipt->id)->first();
            if (! $in || ! $this->validPosting($in) || $receipt->received_on < $transfer->dispatched_on
                || (int) $receipt->received_by === (int) $transfer->dispatched_by
                || $receipt->reference === $transfer->reference) $issues[] = 'receipt_posting_mismatch';
        }
        return $issues;
    }

    private function summarize($transfers): array
    {
        $items = [];
        $transit = 0;
        $issues = [];
        foreach ($transfers as $transfer) {
            $item = $this->result($transfer);
            $items[] = $item;
            $transit = SignedMinor::add($transit, $item['in_transit_minor'] ?? 0);
            foreach ($item['issues'] as $code) $issues[] = ['code' => $code, 'transfer_id' => $transfer->id];
        }
        return ['in_transit_minor' => $issues ? null : $transit, 'issues' => $issues, 'transfers' => $items,
            'certified_balance' => false];
    }

    /** Caller holds register lock; account-level transit counts both directions and must not be summed across accounts. */
    public function inspectAccount(int $id): array
    {
        return $this->summarize(DB::table('treasury_transfers')->where(fn ($q) => $q->where('from_account_id', $id)
            ->orWhere('to_account_id', $id))->orderBy('id')->get());
    }

    public function overview(User $actor): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        return DB::transaction(function (): array {
            $this->lock();
            return $this->summarize(DB::table('treasury_transfers')->orderBy('id')->get());
        }, 3);
    }
}
