<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\User;
use App\Support\CashCustodyAmounts as Cash;
use App\Support\DecimalDomain;
use App\Support\MoneyBoundary;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Only a verified duplicate BOOKKEEPING entry, with no second receipt of funds.
 * Physical refunds and transfers require separate financial document types.
 */
final class ClosedCashCorrectionService
{
    public function correctDuplicate(Payment $payment, int $originalId, User $actor, string $reason, string $evidence): array
    {
        abort_unless($actor->hasRole('super_admin') ||
            ($actor->can('cash_sessions.approve') && $actor->can('payments.void.approve')), 403);
        $reason = trim($reason);
        $evidence = trim($evidence);
        if (mb_strlen($reason) < 10 || mb_strlen($evidence) < 10 || mb_strlen($reason) > 1000 || mb_strlen($evidence) > 2000) {
            throw ValidationException::withMessages(['reason' => 'يجب توثيق سبب التصحيح ودليل عدم استلام دفعة ثانية.']);
        }

        return DB::transaction(function () use ($payment, $originalId, $actor, $reason, $evidence) {
            // Serialize allocation-changing corrections before taking account locks.
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($payment->subscriber_id);
            $payments = Payment::query()->where('subscriber_id', $subscriber->id)
                ->whereIn('id', [$payment->id, $originalId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $payment = $payments->get($payment->id);
            $original = $payments->get($originalId);
            if (! $payment || ! $original || $original->id >= $payment->id || ! $payment->cash_session_id) {
                throw ValidationException::withMessages(['duplicate_of_id' => 'يلزم سند أصلي أقدم لنفس المشترك.']);
            }
            $session = CashSession::query()->lockForUpdate()->findOrFail($payment->cash_session_id);
            abort_if((int) $session->user_id === (int) $actor->id, 403, 'لا يجوز اعتماد تصحيح صندوقك بنفسك.');
            $existing = DB::table('closed_cash_corrections')->where('payment_id', $payment->id)->first();
            if ($existing) {
                abort_unless((int) $existing->duplicate_of_id === $originalId, 409, 'سبق تصحيح هذا السند بمرجع مختلف.');
                return (array) $existing;
            }
            if (\App\Models\PaymentAccountTransfer::whereIn('payment_id', [$payment->id, $original->id])->exists()
                || DB::table('closed_cash_corrections')->where('duplicate_of_id', $payment->id)->exists()
                || $payment->hasPendingVoidRequest() || $original->hasPendingVoidRequest()
                || DB::table('treasury_bank_collections')->where('payment_id', $payment->id)->exists()
                || \App\Models\PaymentRefund::whereIn('payment_id', [$payment->id, $original->id])->exists()) {
                throw ValidationException::withMessages(['payment' => 'السند مرتبط بتصحيح مالي آخر.']);
            }
            $driver = $payment->getConnection()->getDriverName();
            $minor = Cash::positiveStored($payment->getRawOriginal('amount'), $driver, DecimalDomain::D12);
            $originalMinor = Cash::positiveStored($original->getRawOriginal('amount'), $driver, DecimalDomain::D12);
            if (! $session->isApproved() || $payment->isVoided() || $original->isVoided()
                || $minor !== $originalMinor
                || $payment->payment_method !== $original->payment_method) {
                throw ValidationException::withMessages(['payment' => 'يلزم صندوق معتمد وسندان ساريان بنفس المبلغ والطريقة.']);
            }
            $originalPosting = \App\Models\Transaction::query()->where('reference_type', Payment::class)
                ->where('reference_id', $original->id)->where('type', 'payment')->lockForUpdate()->get();
            if ($originalPosting->count() !== 1
                || (int) $originalPosting->first()->subscriber_id !== (int) $subscriber->id
                || Cash::positiveStored($originalPosting->first()->getRawOriginal('amount'), $driver, DecimalDomain::D12) !== $originalMinor) {
                throw ValidationException::withMessages(['duplicate_of_id' => 'لا يوجد قيد أصلي مطابق للسند الذي سيبقى سارياً.']);
            }
            $before = [
                'expected_amount' => $session->expected_amount,
                'counted_amount' => $session->counted_amount,
                'variance_amount' => $session->variance_amount,
                'state' => $session->state,
            ];
            $reversal = app(TransactionService::class)->reverseDocument($subscriber, $payment, 'payment');
            $id = DB::table('closed_cash_corrections')->insertGetId([
                'cash_session_id' => $session->id,
                'payment_id' => $payment->id,
                'duplicate_of_id' => $original->id,
                'approved_by' => $actor->id,
                'reversal_transaction_id' => $reversal->id,
                'amount' => MoneyBoundary::writeDecimal($minor, DecimalDomain::D12),
                'reason' => $reason,
                'evidence' => $evidence,
                'created_at' => now(),
            ]);
            $payment->forceFill([
                'voided_at' => now(), 'void_reason' => $reason,
                'voided_by' => $actor->id, 'voided_via' => 'closed_correction',
            ])->saveQuietly();
            app(PaymentService::class)->recalculateInvoices($subscriber);
            $audit = app(AuditLogger::class)->recordRequired('cash_session.duplicate_corrected', [
                'entity_type' => CashSession::class, 'entity_id' => $session->id,
                'before' => $before,
                'after' => ['correction_id' => $id, 'payment_id' => $payment->id,
                    'duplicate_of_id' => $original->id, 'amount' => MoneyBoundary::writeDecimal($minor, DecimalDomain::D12),
                    'cash_movement' => false, 'reason' => $reason, 'evidence' => $evidence],
                'actor_user_id' => $actor->id, 'source' => 'api',
            ]);
            if (! $audit->exists || $audit->getKey() === null) {
                throw new \RuntimeException('Closed cash correction requires a persisted audit event.');
            }
            return (array) DB::table('closed_cash_corrections')->find($id);
        }, 3);
    }
}
