<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;

class PaymentService
{
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
        $pQuery = Payment::where('subscriber_id', $subscriber->id)->orderBy('payment_date')->orderBy('id');
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
    ): Payment {
        return DB::transaction(function () use ($subscriber, $amount, $invoiceId, $paymentMethod, $referenceNumber, $notes, $reason, $userId) {
            
            // استنتاج العداد من الفاتورة إذا وجدت
            $meterId = null;
            $meter = null;
            if ($invoiceId) {
                $invoice = Invoice::find($invoiceId);
                if ($invoice && $invoice->meter_id) {
                    $meterId = $invoice->meter_id;
                    $meter = $invoice->meter;
                }
            }

            // 1. إنشاء سجل الدفع
            $payment = Payment::create([
                'subscriber_id' => $subscriber->id,
                'meter_id' => $meterId,
                'invoice_id' => $invoiceId,
                'user_id' => $userId,
                'receipt_number' => Payment::generateReceiptNumber(),
                'amount' => $amount,
                'payment_date' => now(),
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,
                'notes' => $notes,
                'reason' => $reason,
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
