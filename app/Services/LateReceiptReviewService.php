<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\User;
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
        $amount = (float) $data['amount'];
        if (! is_finite($amount) || $amount <= 0 || $amount > 9999999999.99 || round($amount, 2) != $amount
            || ! Str::isUuid($data['sync_uuid'])) {
            throw ValidationException::withMessages(['amount' => 'مبلغ السند أو معرّف المزامنة غير صالح.']);
        }
        $claim = [
            'sync_uuid' => strtolower($data['sync_uuid']),
            'cash_session_id' => (int) $data['cash_session_id'],
            'collector_id' => (int) $collector->id,
            'subscriber_id' => (int) $data['subscriber_id'],
            'invoice_id' => isset($data['invoice_id']) ? (int) $data['invoice_id'] : null,
            'submitted_meter_id' => isset($data['meter_id']) ? (int) $data['meter_id'] : null,
            'amount' => number_format($amount, 2, '.', ''),
            'payment_date' => \Carbon\CarbonImmutable::parse($data['payment_date'])->toDateString(),
            'payment_method' => $data['payment_method'],
            'reference_number' => $data['reference_number'] ?? null,
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
        return DB::transaction(function () use ($claim, $collector): array {
            Subscriber::query()->lockForUpdate()->findOrFail($claim['subscriber_id']);
            $this->lockIssuance();
            $existing = DB::table('late_receipt_reviews')->where('sync_uuid', $claim['sync_uuid'])->lockForUpdate()->first();
            if ($existing) {
                foreach ($claim as $field => $value) {
                    abort_unless($field === 'amount' ? (float) $existing->$field === (float) $value
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
            app(AuditLogger::class)->recordRequired('late_receipt.submitted', [
                'entity_type' => 'late_receipt_review', 'entity_id' => $id, 'after' => $claim,
                'actor_user_id' => $collector->id,
            ]);
            return (array) DB::table('late_receipt_reviews')->find($id);
        }, 3);
    }

    /**
     * Only the positive, original physical-cash variance can be allocated.
     * Never infer available cash from legacy totals or later bookkeeping corrections.
     * Approval holds the issuance and session locks when calling this method.
     */
    public function countedCashAllocation(CashSession $session, bool $forApproval = false): array
    {
        $eligible = $session->isApproved() && $session->settlement_basis === 'physical_cash'
            && $session->expected_amount !== null && $session->counted_amount !== null
            && $session->variance_amount !== null;
        $minor = static fn ($value): int => (int) round((float) $value * 100);
        $variance = $minor($session->variance_amount);
        $eligible = $eligible && $variance === $minor($session->counted_amount) - $minor($session->expected_amount);
        $capacity = $eligible ? max(0, $variance) : 0;
        // Use a locking read when approving inside a transaction, not a stale MVCC snapshot.
        $query = DB::table('late_receipt_reviews')->where('cash_session_id', $session->id)
            ->where('status', 'approved')->where('settlement_method', 'already_counted');
        if ($forApproval) {
            $query->lockForUpdate();
        }
        $allocated = $query->get(['amount'])->sum(fn ($row) => $minor($row->amount));

        return [
            'eligible' => $eligible,
            'capacity_minor' => $capacity,
            'allocated_minor' => $allocated,
            'available_minor' => max(0, $capacity - $allocated),
        ];
    }

    public function decide(int $id, User $actor, array $data): array
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
        return DB::transaction(function () use ($id, $actor, $candidate, $evidence, $approve, $reference, $method): array {
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
            $this->lockIssuance();
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
                if ($method === 'verified_non_cash') {
                    throw ValidationException::withMessages(['settlement_method' => 'اعتماد التحصيل غير النقدي المتأخر يتطلب ربط حركة البنك عبر مسار الإثبات البنكي.']);
                }
                if ($method === 'already_counted') {
                    $allocation = $this->countedCashAllocation($session, true);
                    if (! $allocation['eligible'] || (int) round((float) $review->amount * 100) > $allocation['available_minor']) {
                        throw ValidationException::withMessages(['amount' => 'لا يوجد فائض نقدي أصلي مثبت وكافٍ لهذه التسوية.']);
                    }
                }
                abort_if(DB::table('late_receipt_reviews')->where('evidence_reference', $reference)->lockForUpdate()->first(),
                    409, 'مرجع التسوية مستخدم مسبقاً.');
                $payment = app(PaymentService::class)->collectPayment(
                    subscriber: $subscriber, amount: (float) $review->amount,
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
            app(AuditLogger::class)->recordRequired('late_receipt.'.$status, [
                'entity_type' => 'late_receipt_review', 'entity_id' => $id, 'before' => (array) $review,
                'after' => $decision + ['new_cash_movement' => false,
                    'counted_cash_allocation' => $approve && $method === 'already_counted'], 'actor_user_id' => $actor->id,
            ]);
            return (array) DB::table('late_receipt_reviews')->find($id);
        }, 3);
    }
}
