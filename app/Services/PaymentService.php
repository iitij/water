<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    /**
     * إعادة توزيع كل السندات الصالحة (غير الملغاة) على الفواتير.
     * السند الملغى يبقى في قاعدة البيانات للأثر، لكنه لا يخصم شيئاً.
     */
    public function recalculateInvoices(Subscriber $subscriber, ?int $meterId = null): void
    {
        // The optional meter ID is retained for existing callers, but it never
        // scopes money. Only the subscriber is the financial account.
        DB::transaction(function () use ($subscriber): void {
            Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);

            $invoices = Invoice::where('subscriber_id', $subscriber->id)
                ->where('status', '!=', 'draft')
                ->orderBy('bill_date')->orderBy('id')->get();
            $payments = Payment::where('subscriber_id', $subscriber->id)
                ->whereNull('voided_at')
                ->orderBy('payment_date')->orderBy('id')->get();

            // Use integer minor units inside allocation so cents cannot disappear.
            $paid = [];
            $caps = [];
            foreach ($invoices as $invoice) {
                $paid[$invoice->id] = 0;
                $caps[$invoice->id] = max(0, (int) round((float) $invoice->total_amount * 100));
            }

            foreach ($payments as $payment) {
                $remaining = max(0, (int) round((float) $payment->amount * 100));
                $target = $payment->invoice_id;
                if ($target !== null && isset($caps[$target])) {
                    $applied = min($remaining, $caps[$target] - $paid[$target]);
                    $paid[$target] += $applied;
                    $remaining -= $applied;
                }
                foreach ($invoices as $invoice) {
                    if ($remaining === 0) {
                        break;
                    }
                    $id = $invoice->id;
                    $applied = min($remaining, $caps[$id] - $paid[$id]);
                    $paid[$id] += $applied;
                    $remaining -= $applied;
                }
            }

            // Persist all allocations before deriving any cumulative status.
            foreach ($invoices as $invoice) {
                $invoice->amount_paid = number_format($paid[$invoice->id] / 100, 2, '.', '');
                if ($invoice->isDirty('amount_paid')) {
                    $invoice->saveQuietly();
                }
            }
            foreach ($invoices as $invoice) {
                $invoice->updateStatus();
            }
        });
    }

    public function distributePayment(Payment $payment): void
    {
        // One replay algorithm for a new receipt, edits, voids and new invoices.
        $this->recalculateInvoices($payment->subscriber);
    }

    /**
     * إلغاء سند قبض. لا يُحذف السند أبداً: يُوسم بالسبب والمستخدم، ثم تُعاد
     * الفواتير والأرصدة كما لو لم يكن، ويُسجَّل الحدث في سجل التدقيق.
     *
     * مرجع: خطة الإصدار 2.0 — الفصل 5.3.
     */
    public function voidPayment(
        Payment $payment,
        string $reason,
        ?int $userId = null,
        string $source = 'api',
    ): Payment {
        $reason = trim($reason);
        if (\Illuminate\Support\Str::length($reason) < 10) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'reason' => ['سبب الإلغاء مطلوب ولا يقل عن 10 أحرف.'],
            ]);
        }

        return DB::transaction(function () use ($payment, $reason, $userId, $source) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->voided_at !== null) {
                return $payment;
            }

            $before = [
                'receipt_number' => $payment->receipt_number,
                'amount' => (float) $payment->amount,
                'invoice_id' => $payment->invoice_id,
                'meter_id' => $payment->meter_id,
                'cash_session_id' => $payment->cash_session_id,
            ];

            $payment->forceFill([
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by' => $userId,
                'voided_via' => $source,
            ])->save();

            // إزالة أثر السند من دفتر الأستاذ ثم إعادة احتساب الأرصدة.
            \App\Models\Transaction::where('reference_type', Payment::class)
                ->where('reference_id', $payment->id)
                ->delete();

            $transactionService = new TransactionService();
            if ($payment->meter_id) {
                if ($meter = \App\Models\Meter::find($payment->meter_id)) {
                    $transactionService->updateMeterBalance($meter);
                }
            }
            if ($payment->subscriber) {
                $transactionService->updateSubscriberBalance($payment->subscriber);
                $this->recalculateInvoices($payment->subscriber, $payment->meter_id);
            }

            app(\App\Services\AuditLogger::class)->record('payment.voided', [
                'entity_type' => Payment::class,
                'entity_id' => $payment->id,
                'before' => $before,
                'after' => [
                    'voided_at' => optional($payment->voided_at)->toIso8601String(),
                    'void_reason' => $reason,
                    'voided_by' => $userId,
                ],
                'actor_user_id' => $userId,
                'source' => $source,
            ]);

            return $payment->fresh(['subscriber', 'meter', 'invoice', 'user']);
        });
    }

    /**
     * حدّ الإلغاء المالي: السند الذي يبلغ هذا المبلغ أو يتجاوزه يحتاج اعتماد مشرف.
     * القيمة صفر تعني أن الخاصية معطّلة (سلوك v1 متوافق).
     */
    public function voidApprovalThreshold(): float
    {
        try {
            return max(0.0, (float) Setting::get('void_approval_threshold', 0));
        } catch (\Throwable) {
            return 0.0;
        }
    }

    public function requiresVoidApproval(Payment $payment): bool
    {
        $threshold = $this->voidApprovalThreshold();

        return $threshold > 0 && (float) $payment->amount >= $threshold;
    }

    /**
     * طلب إلغاء سند: يُنفَّذ فوراً إن كان تحت الحد، وإلا يُسجَّل طلباً معلّقاً.
     *
     * @return array{payment: Payment, pending: bool}
     */
    public function requestVoid(
        Payment $payment,
        string $reason,
        ?int $userId = null,
        string $source = 'api',
    ): array {
        $reason = trim($reason);
        if (\Illuminate\Support\Str::length($reason) < 10) {
            throw ValidationException::withMessages([
                'reason' => ['سبب الإلغاء مطلوب ولا يقل عن 10 أحرف.'],
            ]);
        }

        if (! $this->requiresVoidApproval($payment)) {
            return ['payment' => $this->voidPayment($payment, $reason, $userId, $source), 'pending' => false];
        }

        // طلب معلّق سابق: نُعيده كما هو (idempotent) بدل تكرار الطلب.
        if ($payment->hasPendingVoidRequest()) {
            return ['payment' => $payment, 'pending' => true];
        }

        if ($payment->isVoided()) {
            return ['payment' => $payment, 'pending' => false];
        }

        $payment->forceFill([
            'void_requested_at' => now(),
            'void_requested_by' => $userId,
            'void_request_reason' => $reason,
            'void_decision_notes' => null,
            'void_decided_by' => null,
            'void_decided_at' => null,
        ])->saveQuietly();

        app(AuditLogger::class)->record('payment.void_requested', [
            'entity_type' => Payment::class,
            'entity_id' => $payment->id,
            'after' => [
                'receipt_number' => $payment->receipt_number,
                'amount' => (float) $payment->amount,
                'reason' => $reason,
                'threshold' => $this->voidApprovalThreshold(),
            ],
            'actor_user_id' => $userId,
            'source' => $source,
        ]);

        return ['payment' => $payment->fresh(), 'pending' => true];
    }

    /**
     * قرار المشرف في طلب إلغاء معلّق.
     *
     * الموافقة تستخدم نفس مسار `voidPayment` (حذف حركات الدفتر وإعادة الاحتساب)
     * فلا يوجد مساران مختلفان لإلغاء سند.
     */
    public function decideVoidRequest(
        Payment $payment,
        bool $approve,
        User $actor,
        ?string $notes = null,
        string $source = 'api',
    ): Payment {
        $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

        if ($payment->isVoided()) {
            return $payment;
        }

        if (! $payment->hasPendingVoidRequest()) {
            throw ValidationException::withMessages([
                'payment' => ['لا يوجد طلب إلغاء معلّق على هذا السند.'],
            ]);
        }

        $reason = (string) ($payment->void_request_reason ?? '');
        $notes = $notes === null ? null : trim($notes);

        if ($approve) {
            $voider = $actor;
            $payment = $this->voidPayment($payment, $reason, $voider->id, 'approval');
            $payment->forceFill([
                'void_decision_notes' => $notes,
                'void_decided_by' => $actor->id,
                'void_decided_at' => now(),
            ])->saveQuietly();

            app(AuditLogger::class)->record('payment.void_approved', [
                'entity_type' => Payment::class,
                'entity_id' => $payment->id,
                'after' => [
                    'receipt_number' => $payment->receipt_number,
                    'reason' => $reason,
                    'notes' => $notes,
                ],
                'actor_user_id' => $actor->id,
                'source' => $source,
            ]);

            return $payment->fresh(['subscriber', 'meter', 'invoice', 'user']);
        }

        $payment->forceFill([
            'void_requested_at' => null,
            'void_requested_by' => null,
            'void_request_reason' => null,
            'void_decision_notes' => $notes,
            'void_decided_by' => $actor->id,
            'void_decided_at' => now(),
        ])->saveQuietly();

        app(AuditLogger::class)->record('payment.void_rejected', [
            'entity_type' => Payment::class,
            'entity_id' => $payment->id,
            'after' => [
                'receipt_number' => $payment->receipt_number,
                'notes' => $notes,
            ],
            'actor_user_id' => $actor->id,
            'source' => $source,
        ]);

        return $payment->fresh(['subscriber', 'meter', 'invoice', 'user']);
    }

    /**
     * تحصيل دفعة من مشترك
     */
    public function collectPayment(
        Subscriber $subscriber,
        float $amount,
        ?int $invoiceId = null,
        string $paymentMethod = 'cash',
        ?string $referenceNumber = null,
        ?string $notes = null,
        ?string $reason = null,
        ?int $userId = null,
        ?string $paymentDate = null,
        ?int $requestedMeterId = null,
        ?string $syncUuid = null,
    ): Payment {
        return DB::transaction(function () use ($subscriber, $amount, $invoiceId, $paymentMethod, $referenceNumber, $notes, $reason, $userId, $paymentDate, $requestedMeterId, $syncUuid) {
            Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);
            if (! is_finite($amount) || $amount <= 0 || round($amount, 2) != $amount) {
                throw ValidationException::withMessages(['amount' => 'المبلغ يجب أن يكون موجباً وبحد أقصى منزلتين عشريتين.']);
            }

            if ($syncUuid) {
                $existing = Payment::withTrashed()->where('sync_uuid', $syncUuid)->first();
                if ($existing) {
                    if ($existing->trashed()) {
                        abort(409, 'عملية الدفع المرتبطة بمعرّف المزامنة محذوفة مسبقاً.');
                    }
                    if ((int) $existing->user_id !== (int) ($userId ?? 0)
                        || (int) $existing->subscriber_id !== (int) $subscriber->id
                        || (int) ($existing->invoice_id ?? 0) !== (int) ($invoiceId ?? 0)
                        || round((float) $existing->amount, 2) !== round($amount, 2)
                        || (! $invoiceId && (int) ($existing->meter_id ?? 0) !== (int) ($requestedMeterId ?? 0))
                        || $existing->payment_method !== $paymentMethod) {
                        abort(409, 'معرّف المزامنة مستخدم مسبقاً لعملية دفع مختلفة.');
                    }
                    return $existing;
                }
            }

            // العداد مرجع للحركة فقط؛ الحساب المالي هو المشترك.
            $meterId = $requestedMeterId;
            $meter = $meterId ? \App\Models\Meter::where('subscriber_id', $subscriber->id)->findOrFail($meterId) : null;
            if ($invoiceId) {
                $invoice = Invoice::where('subscriber_id', $subscriber->id)->where('status', '!=', 'draft')->findOrFail($invoiceId);
                $meterId = $invoice->meter_id;
                $meter = $invoice->meter;
            }

            // ربط السند بالصندوق المفتوح للمحصّل نفسه، إن وُجد.
            // السند بلا صندوق يبقى صالحاً (توافق خلفي) لكنه لا يدخل تسوية يومية.
            $cashSessionId = null;
            if ($userId) {
                $cashSessionId = \App\Models\CashSession::query()
                    ->where('user_id', $userId)
                    ->where('state', \App\Models\CashSession::STATE_OPEN)
                    ->latest('id')
                    ->value('id');
            }

            // 1. إنشاء سجل الدفع
            $payment = Payment::create([
                'subscriber_id' => $subscriber->id,
                'meter_id' => $meterId,
                'invoice_id' => $invoiceId,
                'user_id' => $userId,
                'cash_session_id' => $cashSessionId,
                'receipt_number' => Payment::generateReceiptNumber(),
                'amount' => $amount,
                'payment_date' => $paymentDate ?? now()->toDateString(),
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,
                'notes' => $notes,
                'reason' => $reason,
                'sync_uuid' => $syncUuid,
            ]);

            // 2. توزيع الدفعة على الفواتير المستحقة
            $this->distributePayment($payment);

            // 3. تسجيل العملية في دفتر الأستاذ (الـ Ledger) مع تمرير العداد
            $transactionService = new TransactionService();
            $transactionService->recordTransaction(
                subscriber: $subscriber,
                type: 'payment',
                amount: $amount, // كامل المبلغ المدفوع
                description: 'سند قبض رقم ' . $payment->receipt_number,
                reference: $payment,
                meter: $meter
            );

            // Dispatch WhatsApp Notification (if enabled)
            // Removed: WhatsApp is now click-to-chat

            if (app(\App\Services\WhatsAppService::class)->isEnabled() && in_array(app(\App\Services\WhatsAppService::class)->getMethod(), ['meta_api', 'local_api'])) {
                if (\App\Models\Setting::get('auto_send_whatsapp_payment', false)) {
                    \App\Jobs\SendWhatsAppPaymentJob::dispatch($payment);
                }
            }

            return $payment;
        });
    }
}
