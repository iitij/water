<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\User;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\MoneyBoundary;
use App\Support\SignedMinor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LateReceiptReviewService
{
    public function canDecide(User $actor): bool
    {
        return $actor->hasRole('super_admin')
            || ($actor->can('cash_sessions.approve') && $actor->can('payments.void.approve'));
    }

    private function lockIssuance(): void
    {
        if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
            throw new \LogicException('Payment issuance lock is missing.');
        }
    }

    public function submit(User $collector, array $data): array
    {
        $amount = Cash::input($data['amount'], 'amount', DecimalDomain::D12, 1);
        if (! Str::isUuid($data['sync_uuid'])) {
            throw ValidationException::withMessages(['amount' => 'مبلغ السند أو معرّف المزامنة غير صالح.']);
        }
        $claim = [
            'sync_uuid' => strtolower($data['sync_uuid']),
            'cash_session_id' => (int) $data['cash_session_id'],
            'collector_id' => (int) $collector->id,
            'subscriber_id' => (int) $data['subscriber_id'],
            'invoice_id' => isset($data['invoice_id']) ? (int) $data['invoice_id'] : null,
            'submitted_meter_id' => isset($data['meter_id']) ? (int) $data['meter_id'] : null,
            'amount' => MoneyBoundary::writeDecimal($amount, DecimalDomain::D12),
            'payment_date' => \Carbon\CarbonImmutable::parse($data['payment_date'])->toDateString(),
            'payment_method' => $data['payment_method'],
            'reference_number' => $data['reference_number'] ?? null,
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
        return DB::transaction(function () use ($claim, $collector): array {
            $this->lockIssuance();
            Subscriber::query()->lockForUpdate()->findOrFail($claim['subscriber_id']);
            $existing = DB::table('late_receipt_reviews')->where('sync_uuid', $claim['sync_uuid'])->lockForUpdate()->first();
            if ($existing) {
                foreach ($claim as $field => $value) {
                    abort_unless($field === 'amount' ? $this->receiptMinor($existing->$field) === MoneyBoundary::numericInput($value, DecimalDomain::D12, false, 1)
                        : (string) $existing->$field === (string) $value, 409, 'معرّف المزامنة مرتبط بمراجعة مختلفة.');
                }
                return (array) $existing;
            }
            abort_if(DB::table('noncash_collection_claims')->where('sync_uuid', $claim['sync_uuid'])->exists(), 409, 'المعرف مرتبط بطلب تحصيل غير نقدي.');
            abort_if(Payment::withTrashed()->where('sync_uuid', $claim['sync_uuid'])->lockForUpdate()->first(),
                409, 'سبق تسجيل سند بهذا المعرّف؛ تحقق من السند الأصلي.');
            $session = CashSession::query()->lockForUpdate()->findOrFail($claim['cash_session_id']);
            abort_unless((int) $session->user_id === (int) $collector->id, 403);
            abort_if($session->isOpen() || ! $session->closed_at, 409, 'الصندوق ليس مغلقاً.');
            abort_if($claim['payment_date'] < $session->session_date->toDateString()
                || $claim['payment_date'] > $session->closed_at->toDateString(), 409, 'تاريخ التحصيل خارج فترة الصندوق.');
            if ($claim['invoice_id']) {
                Invoice::query()->where('subscriber_id', $claim['subscriber_id'])->findOrFail($claim['invoice_id']);
            }
            // Submitted meter is provenance only; never move money to a different subscriber.
            $id = DB::table('late_receipt_reviews')->insertGetId($claim + ['status' => 'pending',
                'created_at' => now(), 'updated_at' => now()]);
            $this->requiredAudit('late_receipt.submitted', [
                'entity_type' => 'late_receipt_review', 'entity_id' => $id, 'after' => $claim,
                'actor_user_id' => $collector->id,
            ]);
            return (array) DB::table('late_receipt_reviews')->find($id);
        }, 3);
    }

    private function requiredAudit(string $event, array $context): void
    {
        $record = app(AuditLogger::class)->recordRequired($event, $context);
        if (! $record->exists || $record->getKey() === null) {
            throw new \RuntimeException('Late receipt operation requires a persisted audit event.');
        }
    }

    private function receiptMinor(mixed $raw): int
    {
        return Cash::positiveStored($raw, DB::connection()->getDriverName(), DecimalDomain::D12);
    }

    /**
     * Only the positive, original physical-cash variance can be allocated.
     * Never infer available cash from legacy totals or later bookkeeping corrections.
     * Approval holds the issuance and session locks when calling this method.
     */
    public function countedCashAllocation(CashSession $session, bool $forApproval = false): array
    {
        $driver = $session->getConnection()->getDriverName();
        $expected = Cash::stored($session->getRawOriginal('expected_amount'), $driver, DecimalDomain::D14, false, true);
        $counted = Cash::stored($session->getRawOriginal('counted_amount'), $driver, DecimalDomain::D14, false, true);
        $variance = Cash::stored($session->getRawOriginal('variance_amount'), $driver, DecimalDomain::D14, true, true);
        $eligible = $session->isApproved() && $session->settlement_basis === 'physical_cash'
            && Cash::consistent($counted, $expected, $variance);
        $capacity = $eligible ? max(0, $variance) : 0;
        // Use a locking read when approving inside a transaction, not a stale MVCC snapshot.
        $query = DB::table('late_receipt_reviews')->where('cash_session_id', $session->id)
            ->where('status', 'approved')->where('settlement_method', 'already_counted');
        if ($forApproval) {
            $query->lockForUpdate();
        }
        $allocated = SignedMinor::sum($query->orderBy('id')->get(['amount'])->map(fn ($row) => $this->receiptMinor($row->amount)));
        // Preserve the observed allocation, but do not permit new approval against an inconsistent snapshot.
        $eligible = $eligible && $allocated <= $capacity;

        return [
            'eligible' => $eligible,
            'capacity_minor' => $capacity,
            'allocated_minor' => $allocated,
            'available_minor' => $eligible ? SignedMinor::subtract($capacity, $allocated) : 0,
        ];
    }

    public function decide(int $id, User $actor, array $data): array
    {
        return $this->decideInternal($id, $actor, $data, false);
    }

    private function decideInternal(int $id, User $actor, array $data, bool $verifiedCashDestination): array
    {
        abort_unless($this->canDecide($actor), 403);
        $candidate = DB::table('late_receipt_reviews')->find($id);
        abort_unless($candidate, 404);
        $evidence = trim($data['evidence']);
        $approve = (bool) $data['approve'];
        $reference = $approve ? mb_strtoupper(trim($data['evidence_reference'] ?? '')) : null;
        $method = $approve ? ($data['settlement_method'] ?? null) : null;
        if (mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000
            || ($approve && (mb_strlen($reference) < 3 || mb_strlen($reference) > 150))) {
            throw ValidationException::withMessages(['evidence' => 'يلزم دليل مستقل ومرجع تسوية صالح.']);
        }
        return DB::transaction(function () use ($id, $actor, $candidate, $evidence, $approve, $reference, $method, $verifiedCashDestination): array {
            $this->lockIssuance();
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
            $review = DB::table('late_receipt_reviews')->where('id', $id)->lockForUpdate()->first();
            abort_if((int) $review->collector_id === (int) $actor->id, 403, 'لا يجوز اعتماد مطالبتك بنفسك.');
            $status = $approve ? 'approved' : 'rejected';
            if ($review->status !== 'pending') {
                abort_unless($review->status === $status && (int) $review->decided_by === (int) $actor->id
                    && $review->evidence === $evidence && $review->evidence_reference === $reference
                    && $review->settlement_method === $method && $review->bank_clearance_reference === null, 409, 'سبق البت في المطالبة.');
                return (array) $review;
            }
            $paymentId = null;
            if ($approve) {
                $session = CashSession::query()->lockForUpdate()->findOrFail($review->cash_session_id);
                abort_unless($session->isApproved() && (int) $session->user_id === (int) $review->collector_id, 409, 'يلزم اعتماد الصندوق الأصلي أولاً.');
                $allowedMethods = $review->payment_method === 'cash'
                    ? ['separate_handover', 'already_counted'] : ['verified_non_cash'];
                if (! in_array($method, $allowedMethods, true)) {
                    throw ValidationException::withMessages(['settlement_method' => 'طريقة التسوية لا تتوافق مع وسيلة التحصيل.']);
                }
                if ($method === 'separate_handover' && ! $verifiedCashDestination) {
                    throw ValidationException::withMessages(['settlement_method' => 'التسليم النقدي المستقل يتطلب تحديد الخزينة وتاريخ الاستلام عبر مسار التسليم النقدي.']);
                }
                if ($method === 'verified_non_cash') {
                    throw ValidationException::withMessages(['settlement_method' => 'اعتماد التحصيل غير النقدي المتأخر يتطلب ربط حركة البنك عبر مسار الإثبات البنكي.']);
                }
                if ($method === 'already_counted') {
                    $allocation = $this->countedCashAllocation($session, true);
                    if (! $allocation['eligible'] || $this->receiptMinor($review->amount) > $allocation['available_minor']) {
                        throw ValidationException::withMessages(['amount' => 'لا يوجد فائض نقدي أصلي مثبت وكافٍ لهذه التسوية.']);
                    }
                }
                abort_if(DB::table('late_receipt_reviews')->where('evidence_reference', $reference)->lockForUpdate()->first()
                    || DB::table('treasury_accounts')->where('opening_reference', $reference)->exists()
                    || DB::table('treasury_postings')->where('evidence_reference', $reference)->exists()
                    || DB::table('cash_handovers')->where('reference', $reference)->exists()
                    || DB::table('payment_refunds')->where('disbursement_reference', $reference)->exists(),
                    409, 'مرجع التسوية مستخدم مسبقاً.');
                $payment = app(PaymentService::class)->collectPayment(
                    subscriber: $subscriber, amount: MoneyBoundary::writeDecimal($this->receiptMinor($review->amount), DecimalDomain::D12),
                    invoiceId: $review->invoice_id, paymentMethod: $review->payment_method,
                    referenceNumber: $review->reference_number, notes: $review->notes, reason: $review->reason,
                    userId: $review->collector_id, paymentDate: $review->payment_date,
                    syncUuid: $review->sync_uuid, lateReviewId: $id,
                );
                $paymentId = $payment->id;
            }
            $decision = ['status' => $status, 'payment_id' => $paymentId, 'decided_by' => $actor->id,
                'decided_at' => now(), 'settlement_method' => $method,
                'evidence_reference' => $reference, 'evidence' => $evidence, 'updated_at' => now()];
            DB::table('late_receipt_reviews')->where('id', $id)->update($decision);
            $this->requiredAudit('late_receipt.'.$status, [
                'entity_type' => 'late_receipt_review', 'entity_id' => $id, 'before' => (array) $review,
                'after' => $decision + ['new_cash_movement' => false,
                    'counted_cash_allocation' => $approve && $method === 'already_counted'], 'actor_user_id' => $actor->id,
            ]);
            return (array) DB::table('late_receipt_reviews')->find($id);
        }, 3);
    }

    /** Separate physical receipt, outside the original counted till. */
    public function receiveCash(int $id, User $actor, int $accountId, string $receivedOn, string $reference, string $evidence): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $reference = mb_strtoupper(trim($reference));
        $evidence = trim($evidence);
        if (mb_strlen($reference) < 3 || mb_strlen($reference) > 150 || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $receivedOn)
            || ! checkdate((int) substr($receivedOn, 5, 2), (int) substr($receivedOn, 8, 2), (int) substr($receivedOn, 0, 4))
            || $receivedOn > today()->toDateString()) {
            throw ValidationException::withMessages(['received_on' => 'يلزم تاريخ استلام فعلي ومرجع ودليل صالح.']);
        }
        $candidate = DB::table('late_receipt_reviews')->find($id);
        abort_unless($candidate, 404);
        return DB::transaction(function () use ($id, $actor, $accountId, $receivedOn, $reference, $evidence, $candidate): array {
            $this->lockIssuance();
            Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
            $review = DB::table('late_receipt_reviews')->where('id', $id)->lockForUpdate()->first();
            abort_unless((int) $review->subscriber_id === (int) $candidate->subscriber_id, 409);
            abort_if((int) $review->collector_id === (int) $actor->id, 403);
            $session = CashSession::query()->lockForUpdate()->findOrFail($review->cash_session_id);
            $account = DB::table('treasury_accounts')->where('id', $accountId)->lockForUpdate()->first();
            abort_unless($account, 404);
            if ($review->payment_method !== 'cash' || ! $session->isApproved() || ! $session->closed_at
                || (int) $session->user_id !== (int) $review->collector_id
                || $account->channel !== 'cash' || $receivedOn < $account->opening_on
                || $receivedOn < $session->closed_at->toDateString() || $receivedOn < $review->payment_date) {
                throw ValidationException::withMessages(['account_id' => 'يلزم صندوق أصلي معتمد وخزينة نقدية وتاريخ استلام لا يسبق الإغلاق والافتتاح.']);
            }
            $existing = DB::table('treasury_late_cash_receipts')->where('late_receipt_review_id', $id)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->treasury_account_id === $accountId && (int) $existing->received_by === (int) $actor->id
                    && $existing->received_on === $receivedOn && $existing->reference === $reference && $existing->evidence === $evidence,
                    409, 'سبق إثبات تسليم مختلف للمطالبة.');
                $posting = DB::table('treasury_postings')->where('treasury_late_cash_receipt_id', $existing->id)->first();
                abort_unless($posting && $this->checkCashPosting($posting), 409, 'إثبات التسليم السابق غير متطابق.');
                return ['review' => (array) $review, 'receipt' => (array) $existing, 'posting' => (array) $posting];
            }
            abort_if(DB::table('treasury_postings')->where('evidence_reference', $reference)->exists()
                || DB::table('treasury_accounts')->where('opening_reference', $reference)->exists()
                || DB::table('cash_handovers')->where('reference', $reference)->exists()
                || DB::table('payment_refunds')->where('disbursement_reference', $reference)->exists()
                || DB::table('treasury_late_cash_receipts')->where('reference', $reference)->exists(),
                409, 'مرجع التسليم مستخدم مسبقاً.');
            // New decisions and exact historical separate-handover decisions can be
            // linked; never convert an already-counted allocation into a second receipt.
            $approved = $this->decideInternal($id, $actor, ['approve' => true, 'settlement_method' => 'separate_handover',
                'evidence_reference' => $reference, 'evidence' => $evidence], true);
            $record = ['late_receipt_review_id' => $id, 'treasury_account_id' => $accountId,
                'amount_minor' => $this->receiptMinor($approved['amount']), 'received_on' => $receivedOn,
                'reference' => $reference, 'evidence' => $evidence, 'received_by' => $actor->id];
            $receiptId = DB::table('treasury_late_cash_receipts')->insertGetId($record + ['created_at' => now(), 'updated_at' => now()]);
            $postingId = DB::table('treasury_postings')->insertGetId([
                'treasury_account_id' => $accountId, 'treasury_late_cash_receipt_id' => $receiptId,
                'kind' => 'late_cash_receipt', 'amount_minor' => $record['amount_minor'], 'effective_on' => $receivedOn,
                'evidence_reference' => $reference, 'posted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $posting = DB::table('treasury_postings')->find($postingId);
            if (! $this->checkCashPosting($posting)) throw new \LogicException('Late cash receipt posting inconsistent.');
            $this->requiredAudit('treasury.late_cash_received', [
                'entity_type' => 'treasury_late_cash_receipt', 'entity_id' => $receiptId, 'actor_user_id' => $actor->id,
                'after' => $record + ['payment_id' => $approved['payment_id'], 'posting_id' => $postingId, 'original_till_changed' => false],
            ]);
            return ['review' => $approved, 'receipt' => (array) DB::table('treasury_late_cash_receipts')->find($receiptId), 'posting' => (array) $posting];
        }, 3);
    }

    public function checkCashPosting(object $posting): bool
    {
        if ($posting->kind !== 'late_cash_receipt' || ! $posting->treasury_late_cash_receipt_id
            || $posting->cash_handover_id || $posting->payment_refund_id || $posting->cash_session_funding_id
            || $posting->treasury_transfer_id || $posting->treasury_transfer_receipt_id
            || $posting->treasury_bank_fee_id || $posting->treasury_cash_adjustment_id || $posting->treasury_bank_collection_id) return false;
        $receipt = DB::table('treasury_late_cash_receipts')->find($posting->treasury_late_cash_receipt_id);
        $review = $receipt ? DB::table('late_receipt_reviews')->find($receipt->late_receipt_review_id) : null;
        $payment = $review ? Payment::find($review->payment_id) : null;
        $session = $review ? CashSession::find($review->cash_session_id) : null;
        $account = $receipt ? DB::table('treasury_accounts')->find($receipt->treasury_account_id) : null;
        if (! $receipt || ! $review || ! $payment || ! $session || ! $account || $account->channel !== 'cash'
            || ! $session->isApproved() || ! $session->closed_at || $payment->isVoided()
            || $review->status !== 'approved' || $review->settlement_method !== 'separate_handover'
            || $review->payment_method !== 'cash' || $payment->payment_method !== 'cash'
            || $payment->cash_session_id !== null
            || (int) $review->subscriber_id !== (int) $payment->subscriber_id
            || (int) ($review->invoice_id ?? 0) !== (int) ($payment->invoice_id ?? 0)
            || (int) $review->collector_id !== (int) $session->user_id || (int) $review->collector_id !== (int) $payment->user_id
            || (int) $receipt->received_by === (int) $review->collector_id || (int) $receipt->received_by !== (int) $review->decided_by
            || (int) $receipt->received_by !== (int) $posting->posted_by
            || $review->sync_uuid !== $payment->sync_uuid || $review->payment_date !== $payment->payment_date->toDateString()
            || ($review->reference_number ?? '') !== ($payment->reference_number ?? '')
            || ! $this->matchingCashAmounts($receipt, $review, $payment, $posting)
            || (int) $receipt->treasury_account_id !== (int) $posting->treasury_account_id
            || $receipt->reference !== $review->evidence_reference || $receipt->reference !== $posting->evidence_reference
            || $receipt->evidence !== $review->evidence || mb_strlen(trim($receipt->evidence)) < 10
            || $receipt->received_on !== $posting->effective_on || $receipt->received_on < $account->opening_on
            || $receipt->received_on < $session->closed_at->toDateString() || $receipt->received_on < $review->payment_date
            || $receipt->received_on > today()->toDateString()) return false;
        $entries = \App\Models\Transaction::where('reference_type', Payment::class)->where('reference_id', $payment->id)
            ->where('type', 'payment')->get();
        return $entries->count() === 1 && (int) $entries->first()->subscriber_id === (int) $payment->subscriber_id
            && $this->matchesReceiptLedger($entries->first(), $receipt)
            && (int) ($entries->first()->meter_id ?? 0) === (int) ($payment->meter_id ?? 0)
            && ! \App\Models\Transaction::where('reference_type', Payment::class)->where('reference_id', $payment->id)
                ->where('type', 'payment_reversal')->exists();
    }

    private function matchingCashAmounts(object $receipt, object $review, Payment $payment, object $posting): bool
    {
        try {
            $minor = MoneyBoundary::readMinor($receipt->amount_minor, true, DecimalDomain::D12);
            return $minor > 0 && $minor === $this->receiptMinor($review->amount)
                && $minor === $this->receiptMinor($payment->getRawOriginal('amount'))
                && $minor === MoneyBoundary::readMinor($posting->amount_minor, false, DecimalDomain::D12);
        } catch (\InvalidArgumentException|\OverflowException) {
            return false;
        }
    }

    private function matchesReceiptLedger(\App\Models\Transaction $entry, object $receipt): bool
    {
        try {
            return $this->receiptMinor($entry->getRawOriginal('amount'))
                === MoneyBoundary::readMinor($receipt->amount_minor, true, DecimalDomain::D12);
        } catch (\InvalidArgumentException|\OverflowException) {
            return false;
        }
    }

}
