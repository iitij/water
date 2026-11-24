<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Payment;
use App\Models\PaymentAccountTransfer;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reassign an entire verified receipt, without collecting or returning money.
 * The original receipt remains a physical cash record. Only its accounting
 * allocation changes through a separate immutable document and ledger entries.
 */
final class PaymentAccountTransferService
{
    public function transfer(Payment $receipt, int $destinationId, User $actor, string $reason, string $evidence): PaymentAccountTransfer
    {
        abort_unless($actor->hasRole('super_admin') ||
            ($actor->can('cash_sessions.approve') && $actor->can('payments.void.approve')), 403);
        $reason = trim($reason);
        $evidence = trim($evidence);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000 || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000) {
            throw ValidationException::withMessages(['reason' => 'يجب توثيق سبب التحويل وإثبات المشترك الصحيح.']);
        }
        if ($destinationId === (int) $receipt->subscriber_id) {
            throw ValidationException::withMessages(['to_subscriber_id' => 'يجب اختيار مشترك مختلف.']);
        }
        return DB::transaction(function () use ($receipt, $destinationId, $actor, $reason, $evidence) {
            $accounts = Subscriber::query()->whereIn('id', [$receipt->subscriber_id, $destinationId])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $accounts->get($receipt->subscriber_id);
            $to = $accounts->get($destinationId);
            if (! $from || ! $to) {
                throw ValidationException::withMessages(['to_subscriber_id' => 'الحساب غير موجود.']);
            }
            $receipt = Payment::query()->lockForUpdate()->findOrFail($receipt->id);
            abort_unless((int) $receipt->subscriber_id === (int) $from->id, 409);
            $session = $receipt->cash_session_id ? CashSession::query()->lockForUpdate()->findOrFail($receipt->cash_session_id) : null;
            if ($session) {
                abort_if((int) $session->user_id === (int) $actor->id, 403, 'لا يجوز اعتماد تصحيح صندوقك بنفسك.');
            }
            abort_if($receipt->user_id && (int) $receipt->user_id === (int) $actor->id, 403, 'لا يجوز اعتماد تصحيح سندك بنفسك.');
            $existing = PaymentAccountTransfer::where('payment_id', $receipt->id)->first();
            if ($existing) {
                abort_unless((int) $existing->to_subscriber_id === $destinationId, 409, 'سبق تحويل السند إلى حساب آخر.');
                return $existing;
            }
            if ($receipt->isVoided() || $receipt->hasPendingVoidRequest()
                || ($session && ! $session->isApproved())
                || DB::table('closed_cash_corrections')->where('duplicate_of_id', $receipt->id)->exists()) {
                throw ValidationException::withMessages(['payment' => 'السند ملغى أو قيد تصحيح، أو صندوقه غير معتمد.']);
            }
            $amount = (float) $receipt->amount;
            if (! is_finite($amount) || $amount <= 0) {
                throw ValidationException::withMessages(['payment' => 'مبلغ السند غير صالح.']);
            }
            $before = ['from' => $from->getAccountBalance(), 'to' => $to->getAccountBalance()];
            $transfer = PaymentAccountTransfer::create([
                'payment_id' => $receipt->id,
                'from_subscriber_id' => $from->id,
                'to_subscriber_id' => $to->id,
                'approved_by' => $actor->id,
                'amount' => $receipt->amount,
                'reason' => $reason,
                'evidence' => $evidence,
            ]);
            $ledger = app(TransactionService::class);
            $out = $ledger->reverseDocument($from, $receipt, 'payment');
            $in = $ledger->recordTransaction($to, 'payment_transfer_in', $amount,
                'تصحيح تخصيص سند '.$receipt->receipt_number, $transfer);
            $allocator = app(PaymentService::class);
            $allocator->recalculateInvoices($from);
            $allocator->recalculateInvoices($to);
            $after = ['from' => $from->getAccountBalance(), 'to' => $to->getAccountBalance()];
            if ((int) round(($before['from'] - $after['from']) * 100) !== (int) round($amount * 100)
                || (int) round(($after['to'] - $before['to']) * 100) !== (int) round($amount * 100)) {
                throw new \RuntimeException('Account transfer does not conserve money.');
            }
            app(AuditLogger::class)->recordRequired('payment.account_transferred', [
                'entity_type' => PaymentAccountTransfer::class, 'entity_id' => $transfer->id,
                'before' => $before,
                'after' => $after + ['payment_id' => $receipt->id, 'from_subscriber_id' => $from->id,
                    'to_subscriber_id' => $to->id, 'amount' => $receipt->amount, 'reason' => $reason,
                    'evidence' => $evidence, 'out_transaction_id' => $out->id, 'in_transaction_id' => $in->id,
                    'cash_movement' => false],
                'actor_user_id' => $actor->id, 'source' => 'api',
            ]);
            return $transfer;
        }, 3);
    }
}
