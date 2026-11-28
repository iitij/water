<?php

namespace App\Services;

use App\Support\FinancialAmount;

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
    public function transfer(Payment $receipt, int $destinationId, User $actor, string $reason, string $evidence,
        ?GatedInvoiceTransferWriter $gatedWriter = null): PaymentAccountTransfer
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
        return DB::transaction(function () use ($receipt, $destinationId, $actor, $reason, $evidence, $gatedWriter) {
            if ($gatedWriter === null && DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first()) {
                throw new \DomainException('gated_subscriber_transfer_writer_required');
            }
            // Serialize allocation-changing corrections before taking account locks.
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
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
            $existing = PaymentAccountTransfer::where('payment_id', $receipt->id)->lockForUpdate()->first();
            $registered = false;
            if ($existing !== null) {
                $ids = \App\Models\Transaction::withTrashed()->where(function ($query) use ($receipt, $existing) {
                    $query->where(fn ($q) => $q->where('reference_type', Payment::class)
                        ->where('reference_id', $receipt->id)->where('type', 'payment_reversal'))
                        ->orWhere(fn ($q) => $q->where('reference_type', PaymentAccountTransfer::class)
                            ->where('reference_id', $existing->id)->where('type', 'payment_transfer_in'));
                })->pluck('id');
                foreach ($ids as $id) {
                    if (DB::table('financial_posting_events')->where('scope_key', 'water')
                        ->where('source_type', 'ledger_transaction')->where('leg', 'subscriber')
                        ->where('source_id', (string) $id)->exists()) {
                        $registered = true;
                        break;
                    }
                }
            }
            if (! ($gatedWriter !== null && $gatedWriter->allowsTransfer((int) $receipt->id, $destinationId, (int) $actor->id))
                && ($gatedWriter !== null || $registered || $receipt->hasRegisteredReceipt() || config('financial_candidate.subscriber_transfers') === true)) {
                throw new \DomainException('gated_subscriber_transfer_writer_required');
            }
            if ($existing) {
                abort_unless((int) $existing->to_subscriber_id === $destinationId, 409, 'سبق تحويل السند إلى حساب آخر.');
                return $existing;
            }
            if ($receipt->isVoided() || $receipt->hasPendingVoidRequest()
                || ($session && ! $session->isApproved())
                || DB::table('closed_cash_corrections')->where('duplicate_of_id', $receipt->id)->exists()
                || DB::table('treasury_bank_collections')->where('payment_id', $receipt->id)->exists()
                || \App\Models\PaymentRefund::where('payment_id', $receipt->id)->exists()) {
                throw ValidationException::withMessages(['payment' => 'السند ملغى أو قيد تصحيح، أو صندوقه غير معتمد.']);
            }
            $money = FinancialAmount::validated($receipt->amount, 'payment');
            if ($money->minor <= 0) {
                throw ValidationException::withMessages(['payment' => 'مبلغ السند غير صالح.']);
            }
            $ledger = app(TransactionService::class);
            $before = ['from' => $ledger->accountBalanceMinor($from), 'to' => $ledger->accountBalanceMinor($to)];
            $transfer = new PaymentAccountTransfer([
                'payment_id' => $receipt->id,
                'from_subscriber_id' => $from->id,
                'to_subscriber_id' => $to->id,
                'approved_by' => $actor->id,
                'amount' => $money->decimal(),
                'reason' => $reason,
                'evidence' => $evidence,
            ]);
            if (! $transfer->save(['financial_correction_writer' => $gatedWriter])) {
                throw new \RuntimeException('Transfer document was not persisted.');
            }
            $out = $ledger->reverseDocument($from, $receipt, 'payment', reversalWriter: $gatedWriter);
            $in = $ledger->recordTransaction($to, 'payment_transfer_in', $money->decimal(),
                'تصحيح تخصيص سند '.$receipt->receipt_number, $transfer, correctionWriter: $gatedWriter);
            $allocator = app(PaymentService::class);
            $allocator->recalculateInvoices($from);
            $allocator->recalculateInvoices($to);
            $after = ['from' => $ledger->accountBalanceMinor($from), 'to' => $ledger->accountBalanceMinor($to)];
            if ($after['from'] !== FinancialAmount::add($before['from'], -$money->minor)
                || $after['to'] !== FinancialAmount::add($before['to'], $money->minor)) {
                throw new \RuntimeException('Account transfer does not conserve money.');
            }
            // Preserve the legacy audit presentation keys; exact minor values
            // are additional evidence, never derived from their float projections.
            $auditBalances = static fn (array $balances): array => [
                'from' => (float) ($balances['from'] / 100),
                'to' => (float) ($balances['to'] / 100),
                'from_minor' => $balances['from'], 'to_minor' => $balances['to'],
            ];
            app(AuditLogger::class)->recordRequired('payment.account_transferred', [
                'entity_type' => PaymentAccountTransfer::class, 'entity_id' => $transfer->id,
                'before' => $auditBalances($before),
                'after' => $auditBalances($after) + ['payment_id' => $receipt->id, 'from_subscriber_id' => $from->id,
                    'to_subscriber_id' => $to->id, 'amount' => $receipt->amount, 'reason' => $reason,
                    'evidence' => $evidence, 'out_transaction_id' => $out->id, 'in_transaction_id' => $in->id,
                    'cash_movement' => false],
                'actor_user_id' => $actor->id, 'source' => 'api',
            ]);
            return $transfer;
        }, 3);
    }
}
