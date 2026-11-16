<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    /**
     * توليد فاتورة مجمعة لمشترك
     */
    public function generateInvoice(
        Subscriber $subscriber,
        ?\App\Models\Meter $meter = null,
        ?string $notes = null,
        bool $includeReadings = true,
        string $invoiceType = 'meter'
    ): Invoice {
        // Reject obsolete internal callers even when they bypass the public
        // API and try to create a charge-only invoice without meter readings.
        if ($invoiceType !== 'meter') {
            throw \\Illuminate\\Validation\\ValidationException::withMessages([
                'invoice_type' => ['لا يمكن إصدار فواتير رسوم الاشتراك بعد إزالة هذه الميزة.'],
            ]);
        }

        return DB::transaction(function () use ($subscriber, $meter, $notes, $includeReadings, $invoiceType) {
            if ($includeReadings) {
                if ($invoiceType !== 'meter') {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'invoice_type' => ['لا يمكن تضمين قراءات استهلاك في فاتورة خدمات غير مخصصة للعداد.'],
                    ]);
                }
                $meter = app(CurrentMeterBillingGuard::class)->resolve($subscriber, $meter);
            }
            $unitPrice = \App\Models\Tariff::getActivePrice();

            // 1. حساب الاستهلاك - نأخذ آخر قراءة فقط للاستهلاك الحالي، وما قبلها يُحتسب متأخرات
            $uninvoicedReadings = collect();
            if ($includeReadings) {
                $uninvoicedReadings = Reading::whereHas('meter', function ($q) use ($subscriber, $meter) {
                    $q->where('subscriber_id', $subscriber->id)
                        ->whereNull('removed_at');
                    if ($meter) {
                        $q->where('id', $meter->id);
                    }
                })->where('is_invoiced', false)->orderBy('reading_date')->get();
            }

            $currentConsumptionUnits = 0;
            $previousUnitsAsArrears = 0;
            $consumptionFee = 0;

            if ($uninvoicedReadings->isNotEmpty()) {
                $firstReading = $uninvoicedReadings->first();
                $lastReading = $uninvoicedReadings->last();
                
                // إجمالي وحدات الاستهلاك لجميع القراءات غير المفوترة
                $currentConsumptionUnits = $uninvoicedReadings->sum('consumption_units');
                $consumptionFee = $currentConsumptionUnits * $unitPrice;
            }

            // 2. المتأخرات السابقة (فقط فواتير سابقة غير مسددة من نفس النوع)
            $previousBalance = 0;
            $unpaidInvoicesQuery = Invoice::where('subscriber_id', $subscriber->id)
                ->where('invoice_type', $invoiceType)
                ->whereIn('status', ['issued', 'partially_paid', 'overdue']);
                
            if ($invoiceType === 'meter' && $meter) {
                $unpaidInvoicesQuery->where('meter_id', $meter->id);
            }

            $unpaidInvoices = $unpaidInvoicesQuery->get();
            $previousBalance = $unpaidInvoices->sum(fn($inv) => $inv->getRemainingAmount());

            // ملاحظة: سيتم استدعاء تحديث حالة الفواتير السابقة في نهاية الدالة لتعمل حسب المنطق الصحيح
            // 5. خصم الرصيد الدائن
            $subtotal = $consumptionFee;
            if ($uninvoicedReadings->isEmpty()) {
                throw new \Exception('لا توجد قراءات جديدة لإصدار فاتورة لهذا المشترك.');
            }

            $creditApplied = 0;
            $availableBalance = $meter ? (float) $meter->balance : (float) $subscriber->balance;
            
            if ($availableBalance > 0 && $subtotal > 0) {
                $creditApplied = min($availableBalance, $subtotal);
            }

            // 6. تحديد فترة الفاتورة - بناءً على فترات القراءة المستقلة
            if ($uninvoicedReadings->isNotEmpty()) {
                $firstReading = $uninvoicedReadings->first();
                $lastReading = $uninvoicedReadings->last();

                // أخذ الفترة مباشرة من القراءة (استقلالية الفترات)
                $billingPeriodStart = $firstReading->period_start ?? \Carbon\Carbon::parse($firstReading->reading_date)->startOfMonth()->toDateString();
                $billingPeriodEnd = $lastReading->period_end ?? \Carbon\Carbon::parse($lastReading->reading_date)->endOfMonth()->toDateString();
            } else {
                // في حال عدم وجود قراءات ولكن يوجد استحقاقات أخرى (اشتراك)
                $billingPeriodStart = now()->startOfMonth()->toDateString();
                $billingPeriodEnd = now()->toDateString();
            }

            // 7. حساب الإجمالي (لا نضيف المتأخرات السابقة للإجمالي حتى لا تتضاعف عند جمع الفواتير غير المسددة)
            $totalAmount = $subtotal;

            // 8. إنشاء الفاتورة
            $invoice = Invoice::create([
                'subscriber_id' => $subscriber->id,
                'meter_id' => $meter?->id,
                'invoice_type' => $invoiceType,
                'bill_number' => Invoice::generateBillNumber(),
                'bill_date' => now()->toDateString(),
                'billing_period_start' => $billingPeriodStart,
                'billing_period_end' => $billingPeriodEnd,
                'consumption_fee' => $consumptionFee,
                'previous_balance' => $previousBalance,
                'credit_applied' => $creditApplied,
                'total_amount' => $totalAmount,
                'amount_paid' => 0,
                'status' => 'issued',
                'notes' => $notes,
            ]);

            // 8. إنشاء بنود الفاتورة
            $items = [];

            if ($consumptionFee > 0) {
                $items[] = [
                    'invoice_id' => $invoice->id,
                    'type' => 'consumption',
                    'description' => "استهلاك مياه: {$currentConsumptionUnits} وحدة × {$unitPrice} ريال",
                    'amount' => $consumptionFee,
                ];
            }

            if ($previousBalance > 0) {
                $items[] = [
                    'invoice_id' => $invoice->id,
                    'type' => 'previous_balance',
                    'description' => 'متأخرات سابقة',
                    'amount' => $previousBalance,
                ];
            }

// removed creditApplied item

            foreach ($items as $item) {
                InvoiceItem::create(array_merge($item, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            // 9. تحديث حالة القراءات إلى مفوترة
            $uninvoicedReadings->each(fn ($r) => $r->update(['is_invoiced' => true, 'invoice_id' => $invoice->id]));


            // 12. تسجيل العملية في دفتر الأستاذ (الـ Ledger)
            $transactionService = new TransactionService();
            $transactionService->recordTransaction(
                subscriber: $subscriber,
                type: 'invoice',
                amount: -$subtotal, // الفاتورة تعتبر دين (Debit)
                description: 'إصدار فاتورة رقم ' . $invoice->bill_number,
                reference: $invoice,
                meter: $meter
            );

            // تحديث رصيد المشترك بعد إنشاء الفاتورة والمعاملة المالية
            $transactionService = new TransactionService();
            if ($invoice->meter) {
                $transactionService->updateMeterBalance($invoice->meter);
            } else {
                $transactionService->updateSubscriberBalance($invoice->subscriber);
            }

            // 9. Dispatch WhatsApp Job
            // Removed: WhatsApp is now click-to-chat

            // 13. تحديث حالة الفواتير السابقة لتعمل حسب المنطق الصحيح الآن (بعد إصدار الفاتورة الجديدة)
            if ($unpaidInvoices->count() > 0) {
                foreach ($unpaidInvoices as $unpaidInvoice) {
                    $unpaidInvoice->updateStatus();
                }
            }

            if (app(\App\Services\WhatsAppService::class)->isEnabled() && in_array(app(\App\Services\WhatsAppService::class)->getMethod(), ['meta_api', 'local_api'])) {
                if (\App\Models\Setting::get('auto_send_whatsapp_invoice', false)) {
                    \App\Jobs\SendWhatsAppInvoiceJob::dispatch($invoice);
                }
            }

            return $invoice;
        });
    }


}
