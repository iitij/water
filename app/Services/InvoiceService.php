<?php

namespace App\Services;

use App\Support\FinancialAmount;
use App\Support\ExactMoney;

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
        string $invoiceType = 'meter',
        ?GatedInvoiceTransferWriter $gatedWriter = null
    ): Invoice {
        // Reject obsolete internal callers even when they bypass the public
        // API and try to create a charge-only invoice without meter readings.
        if ($invoiceType !== 'meter') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'invoice_type' => ['نوع الفاتورة غير مدعوم؛ يُسمح بفواتير استهلاك المياه فقط.'],
            ]);
        }

        return DB::transaction(function () use ($subscriber, $meter, $notes, $includeReadings, $invoiceType, $gatedWriter) {
            if ($gatedWriter === null && DB::table('financial_scopes')->where('scope_key', 'water')->lockForUpdate()->first()) {
                throw new \DomainException('gated_invoice_writer_required');
            }
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
            $lockedSubscriber = Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);
            if ($includeReadings) {
                $meter = app(CurrentMeterBillingGuard::class)->resolve($subscriber, $meter);
                $meter = \App\Models\Meter::query()->where('subscriber_id', $subscriber->id)
                    ->lockForUpdate()->findOrFail($meter->id);
                $meter = app(CurrentMeterBillingGuard::class)->resolve($subscriber, $meter);
            }

            if (! ($gatedWriter !== null && $includeReadings && $meter !== null
                && $gatedWriter->allowsIssue((int) $subscriber->id, (int) $meter->id))
                && ($gatedWriter !== null || config('financial_candidate.invoices') === true)) {
                throw new \DomainException('gated_invoice_writer_required');
            }

            // 1. حساب الاستهلاك - نأخذ آخر قراءة فقط للاستهلاك الحالي، وما قبلها يُحتسب متأخرات
            $uninvoicedReadings = collect();
            if ($includeReadings) {
                $uninvoicedReadings = Reading::whereHas('meter', function ($q) use ($subscriber, $meter) {
                    $q->where('subscriber_id', $subscriber->id)
                        ->whereNull('removed_at');
                    if ($meter) {
                        $q->where('id', $meter->id);
                    }
                })->where('is_invoiced', false)
                    ->orderBy('period_start')->orderBy('period_end')->orderBy('id')->lockForUpdate()->get();
            }

            if ($uninvoicedReadings->isEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'readings' => 'لا توجد قراءات جديدة لإصدار فاتورة لهذا المشترك.',
                ]);
            }

            // Validate physical observations before pricing, numbering or any financial write.
            // Existing calendar-only pending invoices retain their historical period policy.
            if ($uninvoicedReadings->contains(fn (Reading $reading) => $reading->period_basis !== 'legacy_calendar')) {
                // The subscriber and current meter are already locked. Re-read the
                // active same-meter chain with current locking reads, including its billed anchor.
                $chain = Reading::query()->where('meter_id', $meter->id)
                    ->orderBy('id')->lockForUpdate()->get()
                    ->map(fn (Reading $reading) => $reading->getRawOriginal())->all();
                try {
                    \App\Support\ObservationBillingChain::assertValid(
                        $chain,
                        $uninvoicedReadings->modelKeys(),
                        $meter->getRawOriginal('installed_at') ?? $lockedSubscriber->getRawOriginal('subscription_start_date'),
                        $meter->getRawOriginal('initial_reading'),
                    );
                } catch (\InvalidArgumentException $error) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['readings' => $error->getMessage()]);
                }
            }

            $pricing = app(HistoricalTariffService::class)->quote($uninvoicedReadings);
            $consumptionFee = $pricing['total'];

            // 2. المتأخرات السابقة (فقط فواتير سابقة غير مسددة من نفس النوع)
            $previousMinor = 0;
            $unpaidInvoicesQuery = Invoice::where('subscriber_id', $subscriber->id)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->whereColumn('total_amount', '>', 'amount_paid');

            $unpaidInvoices = $unpaidInvoicesQuery->get();
            foreach ($unpaidInvoices as $unpaidInvoice) {
                $billed = FinancialAmount::parse($unpaidInvoice->total_amount)->minor;
                $paid = FinancialAmount::parse($unpaidInvoice->amount_paid)->minor;
                if ($billed < 0 || $paid < 0) {
                    throw new \LogicException('Negative billed or paid amount.');
                }
                $previousMinor = FinancialAmount::add($previousMinor, max(0, $billed - $paid));
            }
            $previousBalance = FinancialAmount::storageDecimal($previousMinor, 'previous_balance');

            // ملاحظة: سيتم استدعاء تحديث حالة الفواتير السابقة في نهاية الدالة لتعمل حسب المنطق الصحيح
            // 5. خصم الرصيد الدائن
            $subtotalMoney = ExactMoney::parse($consumptionFee);
            $subtotal = $subtotalMoney->decimal();

            $availableMinor = max(0, app(TransactionService::class)->accountBalanceMinor($subscriber));
            $creditApplied = ExactMoney::fromMinor(min($availableMinor, $subtotalMoney->minor))->decimal();

            // 6. فترة استهلاك المياه؛ وجود قراءة غير مفوترة شرط مؤكد أعلاه
            $billingPeriodStart = $uninvoicedReadings->min('period_start');
            $billingPeriodEnd = $uninvoicedReadings->max('period_end');

            // 7. حساب الإجمالي (لا نضيف المتأخرات السابقة للإجمالي حتى لا تتضاعف عند جمع الفواتير غير المسددة)
            $totalAmount = $subtotal;

            // 8. إنشاء الفاتورة
            $issuedAt = now();
            $invoice = new Invoice([
                'subscriber_id' => $subscriber->id,
                'meter_id' => $meter?->id,
                'invoice_type' => $invoiceType,
                'bill_number' => app(DocumentSequenceService::class)->reserveForIssue('INV', $issuedAt->format('Ym')),
                'bill_date' => $issuedAt->toDateString(),
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
            if (! $invoice->save(['financial_invoice_writer' => $gatedWriter])) {
                throw new \RuntimeException('Invoice was not persisted.');
            }

            // 8. إنشاء بنود الفاتورة
            $items = [];

            if ($subtotalMoney->minor > 0) {
                $items[] = [
                    'invoice_id' => $invoice->id,
                    'type' => 'consumption',
                    'description' => 'استهلاك مياه وفق تفاصيل التعرفة التاريخية',
                    'amount' => $consumptionFee,
                ];
            }

            if ($previousMinor > 0) {
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

            foreach ($pricing['lines'] as $line) {
                DB::table('invoice_tariff_lines')->insert($line + ['invoice_id' => $invoice->id]);
            }

            // 9. تحديث حالة القراءات إلى مفوترة
            $uninvoicedReadings->each(fn ($r) => $r->update(['is_invoiced' => true, 'invoice_id' => $invoice->id]));


            // 12. تسجيل العملية في دفتر الأستاذ (الـ Ledger)
            $transactionService = new TransactionService();
            $transactionService->recordTransaction(
                subscriber: $subscriber,
                type: 'invoice',
                amount: $subtotalMoney->negate()->decimal(), // الفاتورة تعتبر دين (Debit)
                description: 'إصدار فاتورة رقم ' . $invoice->bill_number,
                reference: $invoice,
                meter: $meter,
                invoiceWriter: $gatedWriter
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
                    \App\Jobs\SendWhatsAppInvoiceJob::dispatch($invoice)->afterCommit();
                }
            }

            return $invoice->refresh();
        }, 3);
    }


}
