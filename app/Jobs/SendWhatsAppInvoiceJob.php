<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Setting;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\Enums\Unit;
use Illuminate\Support\Facades\Log;

class SendWhatsAppInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var \App\Models\Invoice */
    public Invoice $invoice;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function handle(WhatsAppService $service)
    {
        $method = $service->getMethod();
        if (!$service->isEnabled() || !in_array($method, ['meta_api', 'local_api'])) {
            return;
        }

        $phone = $this->invoice->subscriber->phone;
        if (empty($phone)) {
            Log::info("WhatsApp Job: No phone number for invoice {$this->invoice->id}");
            return;
        }

        $name = $this->invoice->subscriber->name;
        $amount = number_format($this->invoice->total_amount + $this->invoice->previous_balance, 2) . ' ريال';
        $template = Setting::get('whatsapp_invoice_template', 'new_invoice_template');

        // 1. Generate PDF
        $this->invoice->load(['subscriber.square', 'items']);
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $filename = "Invoice-{$this->invoice->bill_number}.pdf";
        $filePath = $tempDir . '/' . $filename;

        try {
            Pdf::view('pdf.invoice', [
                'invoice' => $this->invoice,
            ])
                ->withBrowsershot(function ($browsershot) {
                    $browsershot->noSandbox();
                })
                ->margins(0, 0, 0, 0, Unit::Millimeter)
                ->format('A5')
                ->landscape()
                ->save($filePath);

            if ($method === 'meta_api') {
                // 2. Upload PDF
                $mediaId = $service->uploadMedia($filePath, $filename);

                // 3. Send Message
                if ($mediaId) {
                    $service->sendTemplateMessage(
                        $phone,
                        $template,
                        [$name, $this->invoice->bill_number, $amount],
                        $mediaId,
                        $filename
                    );
                } else {
                    Log::error("WhatsApp Job: Failed to upload PDF media for invoice {$this->invoice->id}");
                }
            } elseif ($method === 'local_api') {
                $pdfLink = route('invoices.print', $this->invoice);
                
                    $defaultMsg = "🌊 إشعار فاتورة استهلاك مياه 🌊\nمرحباً بك {subscriber_name}،\nتم إصدار فاتورتك رقم (#{bill_number}) للفترة من {period_start} إلى {period_end}.\n\n📌 تفاصيل القراءة والاستهلاك:\n• رقم العداد: {meter_code} | المربع: {square}\n• القراءة السابقة: {previous_reading}\n• القراءة الحالية: {current_reading}\n• كمية الاستهلاك: {consumption_units} وحدة (سعر الوحدة: {unit_price})\n\n💰 تفاصيل الرسوم والحساب:\n• قيمة الاستهلاك: {consumption_fee} ريال\n{if:previous_balance}• رصيد متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n------------------------\n💵 الإجمالي المستحق: {total_amount} ريال\n------------------------\n🧑‍💼 المحصل: {collector}\n\n📄 لمعاينة وتحميل الفاتورة الرسمية (PDF):\n{pdf_url}\n\nشكراً لدفعكم في الموعد المحدد 🌹";
                    $customMsg = \App\Models\Setting::get('reading_invoice_message_template', $defaultMsg);

                $readings = clone $this->invoice->readings;
                if ($readings->isEmpty() && $this->invoice->meter_id && $this->invoice->billing_period_start && $this->invoice->billing_period_end) {
                    $readings = \App\Models\Reading::where('meter_id', $this->invoice->meter_id)
                        ->where('reading_date', '<=', $this->invoice->billing_period_end)
                        ->where('reading_date', '>=', $this->invoice->billing_period_start)
                        ->orderBy('reading_date', 'asc')
                        ->get();
                }
                if ($readings->isEmpty() && $this->invoice->billing_period_start && $this->invoice->billing_period_end) {
                    $readings = \App\Models\Reading::whereHas('meter', function($q) {
                        $q->where('subscriber_id', $this->invoice->subscriber_id);
                    })->where('reading_date', '<=', $this->invoice->billing_period_end)
                    ->where('reading_date', '>=', $this->invoice->billing_period_start)
                    ->orderBy('reading_date', 'asc')->get();
                }
                $sortedReadings = $readings->sortBy('reading_date');
                $firstReading = $sortedReadings->first();
                $lastReading = $sortedReadings->last();

                $previousReading = $firstReading ? $firstReading->previous_reading : '';
                $currentReading = $lastReading ? $lastReading->current_reading : '';
                $consumptionUnits = $readings->sum('consumption_units');
                $consumptionValue = $this->invoice->consumption_fee ?? 0;
                
                $unitPrice = ($consumptionUnits > 0 && $consumptionValue > 0) ? round($consumptionValue / $consumptionUnits, 2) : \App\Models\Tariff::getActivePrice();

                $messageText = str_replace(
                    [
                        '{subscriber_name}', 
                        '{bill_number}', 
                        '{total_amount}', 
                        '{pdf_url}',
                        '{period_start}',
                        '{period_end}',
                        '{meter_code}',
                        '{square}',
                        '{previous_reading}',
                        '{current_reading}',
                        '{consumption_units}',
                        '{unit_price}',
                        '{consumption_fee}',
                        '{previous_balance}',
                        '{collector}'
                    ],
                    [
                        $name, 
                        $this->invoice->bill_number, 
                        number_format((float)(($this->invoice->total_amount ?? 0) + ($this->invoice->previous_balance ?? 0) - ($this->invoice->credit_applied ?? 0)), 2), 
                        $pdfLink,
                        $this->invoice->billing_period_start ? $this->invoice->billing_period_start->format('Y-m-d') : '',
                        $this->invoice->billing_period_end ? $this->invoice->billing_period_end->format('Y-m-d') : '',
                        $this->invoice->meter->unique_code ?? '',
                        $this->invoice->subscriber->square->name ?? '',
                        $previousReading,
                        $currentReading,
                        $consumptionUnits,
                        $unitPrice,
                        number_format((float)$consumptionValue, 2),
                        number_format((float)($this->invoice->previous_balance ?? 0), 2),
                        'إدارة المشروع'
                    ],
                    $customMsg
                );

                // Handle conditional blocks
                $vars = [
                    'previous_balance' => (float)($this->invoice->previous_balance ?? 0),
                ];
                
                foreach ($vars as $key => $val) {
                    if ($val > 0) {
                        $messageText = preg_replace('/\{if:' . $key . '\}(.*?)\{\/if:' . $key . '\}/s', '$1', $messageText);
                    } else {
                        $messageText = preg_replace('/\{if:' . $key . '\}(.*?)\{\/if:' . $key . '\}/s', '', $messageText);
                    }
                }

                $service->sendLocalApiMessage($phone, $messageText, $filePath, $filename);
            }

        } catch (\Exception $e) {
            Log::error("WhatsApp Job Exception: " . $e->getMessage());
        } finally {
            // 4. Cleanup
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
    }
}
