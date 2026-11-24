<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record an already executed, independently evidenced treasury disbursement.
 * This is not a payment gateway, a collector till withdrawal, or approval
 * to send money. Only refundable excess credit is supported.
 */
final class PaymentRefundService
{
    public function record(Payment $payment, User $actor, string $amount, string $reference,
        string $method, string $date, string $reason, string $evidence): PaymentRefund
    {
        abort_unless($actor->hasRole('super_admin') ||
            ($actor->can('cash_sessions.approve') && $actor->can('payments.void.approve')), 403);
        $reference = mb_strtoupper(trim($reference));
        $reason = trim($reason);
        $evidence = trim($evidence);
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $amount)
            || (float) $amount <= 0 || mb_strlen($reference) < 3 || mb_strlen($reference) > 150
            || ! in_array($method, ['treasury_cash', 'bank_transfer'], true)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            || $date > now()->toDateString()
            || mb_strlen($reason) < 10 || mb_strlen($reason) > 1000
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000) {
            throw ValidationException::withMessages(['refund' => 'بيانات الصرف المثبت ومبلغ الاسترداد غير صالحة.']);
        }

        return DB::transaction(function () use ($payment, $actor, $amount, $reference, $method, $date, $reason, $evidence) {
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($payment->subscriber_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless((int) $subscriber->id === (int) $payment->subscriber_id, 409);
            abort_if($payment->user_id && (int) $payment->user_id === (int) $actor->id, 403);
            if ($payment->cash_session_id) {
                $session = CashSession::query()->lockForUpdate()->findOrFail($payment->cash_session_id);
                abort_if((int) $session->user_id === (int) $actor->id, 403);
                if (! $session->isApproved()) {
                    throw ValidationException::withMessages(['payment' => 'يجب اعتماد صندوق السند أولاً.']);
                }
            }

            $minor = static fn ($value): int => (int) round((float) $value * 100);
            $requested = $minor($amount);
            $existing = PaymentRefund::where('disbursement_reference', $reference)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->payment_id === (int) $payment->id
                    && $minor($existing->amount) === $requested
                    && $existing->method === $method
                    && $existing->disbursed_on->toDateString() === $date, 409,
                    'مرجع الصرف مستخدم لعملية أخرى.');
                return $existing;
            }

            if ($payment->isVoided() || $payment->hasPendingVoidRequest()
                || \App\Models\PaymentAccountTransfer::where('payment_id', $payment->id)->exists()
                || DB::table('closed_cash_corrections')->where('duplicate_of_id', $payment->id)->exists()
                || $date < $payment->payment_date->toDateString()) {
                throw ValidationException::withMessages(['payment' => 'السند غير مؤهل لهذا الاسترداد.']);
            }

            if (in_array($payment->payment_method, ['bank_transfer', 'check'], true)) {
                $proof = DB::table('treasury_bank_collections')->where('payment_id', $payment->id)->first();
                $posting = $proof ? DB::table('treasury_postings')->where('treasury_bank_collection_id', $proof->id)->first() : null;
                if (! $posting || ! app(BankReconciliationService::class)->checkCollectionPosting($posting)) {
                    throw ValidationException::withMessages(['payment' => 'يلزم إثبات وصول التحصيل غير النقدي قبل الاسترداد.']);
                }
            }

            $originals = Transaction::where('reference_type', Payment::class)->where('reference_id', $payment->id)
                ->where('type', 'payment')->lockForUpdate()->get();
            if ($originals->count() !== 1 || (int) $originals->first()->subscriber_id !== (int) $subscriber->id
                || $minor($originals->first()->amount) !== $minor($payment->amount)
                || (int) ($originals->first()->meter_id ?? 0) !== (int) ($payment->meter_id ?? 0)
                || Transaction::where('reference_type', Payment::class)->where('reference_id', $payment->id)
                    ->where('type', 'payment_reversal')->exists()) {
                throw ValidationException::withMessages(['payment' => 'لا يوجد قيد قبض أصلي مطابق وسارٍ.']);
            }
            $prior = $minor(PaymentRefund::where('payment_id', $payment->id)->sum('amount'));
            $before = $minor($subscriber->getAccountBalance());
            if ($requested > $minor($payment->amount) - $prior || $requested > $before) {
                throw ValidationException::withMessages(['amount' => 'يتجاوز الاسترداد الرصيد الدائن أو المبلغ المتبقي من السند.']);
            }

            $allocator = app(PaymentService::class);
            $allocator->recalculateInvoices($subscriber);
            $openInvoices = fn () => Invoice::where('subscriber_id', $subscriber->id)
                ->whereNotIn('status', ['draft', 'cancelled'])->whereColumn('amount_paid', '<', 'total_amount')->exists();
            if ($openInvoices()) {
                throw ValidationException::withMessages(['amount' => 'لا يمكن استرداد الرصيد قبل تسوية جميع الفواتير المستحقة.']);
            }
            $refund = PaymentRefund::create([
                'payment_id' => $payment->id, 'subscriber_id' => $subscriber->id, 'recorded_by' => $actor->id,
                'amount' => $amount, 'disbursement_reference' => $reference, 'method' => $method,
                'disbursed_on' => $date, 'reason' => $reason, 'evidence' => $evidence,
            ]);
            $movement = app(TransactionService::class)->recordTransaction($subscriber, 'payment_refund',
                -($requested / 100), 'استرداد رصيد دائن - '.$reference, $refund);
            $allocator->recalculateInvoices($subscriber);
            $after = $minor($subscriber->getAccountBalance());
            if ($after !== $before - $requested || $after < 0 || $openInvoices()) {
                throw ValidationException::withMessages(['amount' => 'الاسترداد سيؤثر على تسوية الفواتير؛ العملية مرفوضة.']);
            }
            app(AuditLogger::class)->recordRequired('payment.refunded', [
                'entity_type' => PaymentRefund::class, 'entity_id' => $refund->id,
                'before' => ['balance_minor' => $before],
                'after' => ['balance_minor' => $after, 'payment_id' => $payment->id,
                    'transaction_id' => $movement->id, 'amount' => $refund->amount,
                    'reference' => $reference, 'method' => $method, 'disbursed_on' => $date,
                    'reason' => $reason, 'evidence' => $evidence, 'cash_movement' => true],
                'actor_user_id' => $actor->id,
            ]);
            return $refund;
        }, 3);
    }
}
