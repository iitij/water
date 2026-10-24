<?php

namespace App\Jobs;

use App\Models\Payment;
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

class SendWhatsAppPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var \App\Models\Payment */
    public Payment $payment;

    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    public function handle(WhatsAppService $service)
    {
        $method = $service->getMethod();
        if (!$service->isEnabled() || !in_array($method, ['meta_api', 'local_api'])) {
            return;
        }

        $phone = $this->payment->meter?->tenant_phone ?? $this->payment->subscriber->phone;
        if (empty($phone)) {
            Log::info("WhatsApp Job: No phone number for payment {$this->payment->id}");
            return;
        }

        $name = $this->payment->meter?->tenant_name ?? $this->payment->subscriber->name;
        $amount = number_format($this->payment->amount, 2) . ' ريال';
        $template = Setting::get('whatsapp_payment_template', 'payment_receipt_template');

        // 1. Generate PDF
        $this->payment->load(['subscriber.square', 'invoice']);
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $filename = "Receipt-{$this->payment->receipt_number}.pdf";
        $filePath = $tempDir . '/' . $filename;

        try {
            Pdf::view('pdf.receipt', [
                'payment' => $this->payment,
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
                    $sent = $service->sendTemplateMessage(
                        $phone,
                        $template,
                        [$name, $this->payment->receipt_number, $amount],
                        $mediaId,
                        $filename
                    );
                    // أثر «ما وصل فعلاً» (خطة 2.0 — الفصل 6.2): لا نسجّل إلا نجاحاً.
                    if ($sent) {
                        $this->markSent('meta_api');
                    }
                } else {
                    Log::error("WhatsApp Job: Failed to upload PDF media for payment {$this->payment->id}");
                }
            } elseif ($method === 'local_api') {
                $defaultMsg = "أهلاً بك {name}،\nاستلمنا دفعتك بنجاح بموجب سند قبض رقم {receipt_number}.\nإجمالي المبلغ المدفوع: {amount} ريال.\nالمتبقي من الفاتورة: {invoice_remaining} ريال.\nإجمالي المتبقي للحساب: {outstanding_balance} ريال.\nالرصيد الدائن: {credit_balance} ريال.\n\nللاطلاع على السند: {pdf_link}\nشكراً لتعاملك معنا.";
                $customMsg = Setting::get('whatsapp_payment_message', $defaultMsg);

                $invoiceRemaining = 0;
                if ($this->payment->invoice) {
                    $invoiceTotal = $this->payment->invoice->total_amount + $this->payment->invoice->previous_balance;
                    $creditApplied = $this->payment->invoice->credit_applied ?? 0;
                    $directPaidBefore = \App\Models\Payment::where('invoice_id', $this->payment->invoice_id)
                        ->where('id', '<', $this->payment->id)
                        ->sum('amount');
                    $paidBefore = $directPaidBefore + $creditApplied;
                    $dueBefore = $invoiceTotal - $paidBefore;
                    $invoiceRemaining = $dueBefore - $this->payment->amount;
                }

                $messageText = str_replace(
                    [
                        '{name}', 
                        '{receipt_number}', 
                        '{amount}',
                        '{invoice_remaining}',
                        '{outstanding_balance}',
                        '{credit_balance}',
                        '{pdf_link}'
                    ],
                    [
                        $name, 
                        $this->payment->receipt_number, 
                        $amount,
                        number_format((float)$invoiceRemaining, 2),
                        number_format((float)$this->payment->subscriber->getOutstandingBalance(), 2),
                        number_format((float)$this->payment->subscriber->balance, 2),
                        route('payments.print', $this->payment)
                    ],
                    $customMsg
                );

                if ($service->sendLocalApiMessage($phone, $messageText, $filePath, $filename)) {
                    $this->markSent('local_api');
                }
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

    /** يسجّل وقت الإرسال وطريقته بلا تشغيل أحداث الموديل (لا نريد إعادة حساب الأرصدة). */
    private function markSent(string $via): void
    {
        try {
            $this->payment->forceFill([
                'whatsapp_sent_at' => now(),
                'whatsapp_sent_via' => $via,
            ])->saveQuietly();
        } catch (\Throwable $error) {
            Log::warning('whatsapp.mark_sent_failed', [
                'payment_id' => $this->payment->id,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
