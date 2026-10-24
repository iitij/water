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
        // 1. Get all invoices
        $query = Invoice::where('subscriber_id', $subscriber->id)->orderBy('bill_date')->orderBy('id');
        if ($meterId) {
            $query->where('meter_id', $meterId);
        } else {
            $query->whereNull('meter_id');
        }
        $invoices = $query->get();

        // 2. Reset them in memory
        foreach ($invoices as $inv) {
            $inv->amount_paid = 0;
            $inv->status = 'issued';
        }

        // 3. Get all payments
        $pQuery = Payment::where('subscriber_id', $subscriber->id)
            ->whereNull('voided_at')
            ->orderBy('payment_date')
            ->orderBy('id');
        if ($meterId) {
            $pQuery->where('meter_id', $meterId);
        } else {
            $pQuery->whereNull('meter_id');
        }
        $payments = $pQuery->get();

        // 4. Distribute each payment
        foreach ($payments as $payment) {
            $remaining = (float) $payment->amount;
            $targetId = $payment->invoice_id;

            // First apply to target
            if ($targetId && $remaining > 0) {
                $target = $invoices->firstWhere('id', $targetId);
                if ($target) {
                    $due = (float) $target->total_amount - (float) $target->amount_paid;
                    $applied = min($remaining, $due);
                    if ($applied > 0) {
                        $target->amount_paid += $applied;
                        $remaining -= $applied;
                    }
                }
            }

            // Then FIFO
            if ($remaining > 0) {
                foreach ($invoices as $inv) {
                    if ($remaining <= 0) break;
                    $due = (float) $inv->total_amount - (float) $inv->amount_paid;
                    if ($due > 0) {
                        $applied = min($remaining, $due);
                        $inv->amount_paid += $applied;
                        $remaining -= $applied;
                    }
                }
            }
        }

        // 5. Save changes and update status
        foreach ($invoices as $inv) {
            if ($inv->isDirty('amount_paid')) {
                $inv->saveQuietly();
            }
            $inv->updateStatus();
        }
    }
    public function distributePayment(Payment $payment): void
    {
        $remainingAmount = $payment->amount;
        $subscriber = $payment->subscriber;
        $meterId = $payment->meter_id;
        $invoiceId = $payment->invoice_id;

        // 1. تطبيق المبلغ على الفاتورة المحددة أولاً (إذا وجدت)
        if ($invoiceId && $remainingAmount > 0) {
            $targetInvoice = Invoice::find($invoiceId);
            if ($targetInvoice && in_array($targetInvoice->status, ['issued', 'partially_paid', 'overdue'])) {
                $totalDue = (float) $targetInvoice->total_amount;
                $alreadyPaid = (float) $targetInvoice->amount_paid;
                $invoiceRemaining = max(0, $totalDue - $alreadyPaid);
                $applied = min($remainingAmount, $invoiceRemaining);

                if ($applied > 0) {
                    $targetInvoice->update(['amount_paid' => $alreadyPaid + $applied]);
                    $targetInvoice->refresh();
                    $targetInvoice->updateStatus();
                    $remainingAmount -= $applied;
                }
            }
        }

        // 2. تطبيق المبلغ المتبقي على أقدم الفواتير غير المسددة المعزولة بالعداد (FIFO)
        if ($remainingAmount > 0) {
            $unpaidInvoicesQuery = Invoice::where('subscriber_id', $subscriber->id)
                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                ->orderBy('bill_date')
                ->orderBy('id');

            if ($invoiceId) {
                $unpaidInvoicesQuery->where('id', '!=', $invoiceId);
            }

            if ($meterId) {
                $unpaidInvoicesQuery->where('meter_id', $meterId);
            } else {
                $unpaidInvoicesQuery->whereNull('meter_id');
            }

            $unpaidInvoices = $unpaidInvoicesQuery->get();

            foreach ($unpaidInvoices as $inv) {
                if ($remainingAmount <= 0) break;

                $totalDue = (float) $inv->total_amount;
                $alreadyPaid = (float) $inv->amount_paid;
                $invoiceRemaining = max(0, $totalDue - $alreadyPaid);
                $applied = min($remainingAmount, $invoiceRemaining);

                if ($applied > 0) {
                    $inv->update(['amount_paid' => $alreadyPaid + $applied]);
                    $inv->refresh();
                    $inv->updateStatus();
                }

                $remainingAmount -= $applied;
            }
        }

        // 3. تحديث حالة الفواتير
        $allInvoicesQuery = Invoice::where('subscriber_id', $subscriber->id)
            ->whereIn('status', ['issued', 'partially_paid', 'overdue']);
            
        if ($meterId) {
            $allInvoicesQuery->where('meter_id', $meterId);
        } else {
            $allInvoicesQuery->whereNull('meter_id');
        }
        
        foreach ($allInvoicesQuery->get() as $inv) {
            $inv->updateStatus();
        }
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

            // استنتاج العداد من الفاتورة، أو من الاختيار الصريح للحساب المعزول.
            $meterId = $requestedMeterId;
            $meter = $meterId ? \App\Models\Meter::where('subscriber_id', $subscriber->id)->findOrFail($meterId) : null;
            if ($invoiceId) {
                $invoice = Invoice::where('subscriber_id', $subscriber->id)->findOrFail($invoiceId);
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
