<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Tariff;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppService
{
    protected string $baseUrl = 'https://graph.facebook.com/v19.0';

    /**
     * تنسيق رقم الهاتف ليصبح بالصيغة الدولية لميتا
     */
    public function formatPhoneNumber(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (Str::startsWith($phone, '0')) {
            $phone = substr($phone, 1);
        }

        if (strlen($phone) === 9 && (Str::startsWith($phone, '7') || Str::startsWith($phone, '1'))) {
            $phone = '967'.$phone;
        }

        return $phone;
    }

    /**
     * التحقق مما إذا كانت الخدمة مفعلة
     */
    public function isEnabled(): bool
    {
        return (bool) Setting::get('whatsapp_enabled', false);
    }

    /**
     * التحقق من الطريقة المفضلة (click_to_chat أو meta_api)
     */
    public function getMethod(): string
    {
        return Setting::get('whatsapp_method', 'click_to_chat');
    }

    /**
     * إنتاج رابط إرسال رسالة واتساب للفاتورة (Click to Chat)
     */
    public function getInvoiceMessageUrl(Invoice $invoice): ?string
    {
        $phone = $invoice->meter?->tenant_phone ?? $invoice->subscriber->phone;
        $formattedPhone = $this->formatPhoneNumber($phone);

        if (! $formattedPhone) {
            return null;
        }

        return 'https://wa.me/'.$formattedPhone.'?text='.rawurlencode($this->invoiceMessage($invoice));
    }

    /** نص رسالة الفاتورة بعد تطبيق القالب والمتغيرات — يُستخدم أيضاً في إيصال الفاتورة للتطبيق. */
    public function invoiceMessage(Invoice $invoice): string
    {
        $name = $invoice->meter?->tenant_name ?? $invoice->subscriber->name;
        $amount = number_format($invoice->total_amount + $invoice->previous_balance, 2);

        // رابط طباعة الفاتورة كميزة إضافية للعميل
        $pdfLink = route('invoices.print', $invoice);

        if ($invoice->invoice_type === 'subscription') {
            $defaultMsg = "🧾 فاتورة رسوم اشتراك 🧾\nمرحباً بك {subscriber_name}،\nتم إصدار فاتورة رسوم اشتراك جديدة رقم (#{bill_number}).\n\nقيمة الرسوم: {subscription_fee} ريال\n{if:previous_balance}• رصيد متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n------------------------\n💵 الإجمالي المستحق: {total_amount} ريال\n------------------------\nالمحصل: {collector}\n\nللاطلاع على الفاتورة وتحميلها (PDF):\n{pdf_url}\n\nشكراً لتعاملكم معنا 🌹";
            $template = Setting::get('subscription_invoice_template', $defaultMsg);
        } elseif ($invoice->invoice_type === 'tanker') {
            $defaultMsg = "🧾 فاتورة رسوم وايت 🧾\nمرحباً بك {subscriber_name}،\nتم إصدار فاتورة رسوم وايت جديدة رقم (#{bill_number}).\n\nقيمة الرسوم: {tanker_fee} ريال\n{if:previous_balance}• رصيد متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n------------------------\n💵 الإجمالي المستحق: {total_amount} ريال\n------------------------\nالمحصل: {collector}\n\nللاطلاع على الفاتورة وتحميلها (PDF):\n{pdf_url}\n\nشكراً لتعاملكم معنا 🌹";
            $template = Setting::get('tanker_invoice_template', $defaultMsg);
        } else {
            $defaultMsg = "🌊 إشعار فاتورة استهلاك مياه 🌊\nمرحباً بك {subscriber_name}،\nتم إصدار فاتورتك رقم (#{bill_number}) للفترة من {period_start} إلى {period_end}.\n\n📌 تفاصيل القراءة والاستهلاك:\n• رقم العداد: {meter_code} | المربع: {square}\n• القراءة السابقة: {previous_reading}\n• القراءة الحالية: {current_reading}\n• كمية الاستهلاك: {consumption_units} وحدة (سعر الوحدة: {unit_price})\n\n💰 تفاصيل الرسوم والحساب:\n• قيمة الاستهلاك: {consumption_fee} ريال\n{if:subscription_fee}• رسوم الاشتراك: {subscription_fee} ريال{/if:subscription_fee}\n{if:tanker_fee}• رسوم الوايت: {tanker_fee} ريال{/if:tanker_fee}\n{if:previous_balance}• رصيد متأخرات سابق: {previous_balance} ريال{/if:previous_balance}\n------------------------\n💵 الإجمالي المستحق: {total_amount} ريال\n------------------------\n🧑‍💼 المحصل: {collector}\n\n📄 لمعاينة وتحميل الفاتورة الرسمية (PDF):\n{pdf_url}\n\nشكراً لدفعكم في الموعد المحدد 🌹";
            $template = Setting::get('reading_invoice_message_template', $defaultMsg);
        }

        $readings = clone $invoice->readings;
        if ($readings->isEmpty() && $invoice->meter_id && $invoice->billing_period_start && $invoice->billing_period_end) {
            $readings = Reading::where('meter_id', $invoice->meter_id)
                ->where('reading_date', '<=', $invoice->billing_period_end)
                ->where('reading_date', '>=', $invoice->billing_period_start)
                ->orderBy('reading_date', 'asc')
                ->get();
        }
        if ($readings->isEmpty() && $invoice->billing_period_start && $invoice->billing_period_end) {
            $readings = Reading::whereHas('meter', function ($q) use ($invoice) {
                $q->where('subscriber_id', $invoice->subscriber_id);
            })->where('reading_date', '<=', $invoice->billing_period_end)
                ->where('reading_date', '>=', $invoice->billing_period_start)
                ->orderBy('reading_date', 'asc')->get();
        }
        $sortedReadings = $readings->sortBy('reading_date');
        $firstReading = $sortedReadings->first();
        $lastReading = $sortedReadings->last();

        $previousReading = $firstReading ? $firstReading->previous_reading : '';
        $currentReading = $lastReading ? $lastReading->current_reading : '';
        $consumptionUnits = $readings->sum('consumption_units');
        $consumptionValue = $invoice->consumption_fee ?? 0;

        $unitPrice = ($consumptionUnits > 0 && $consumptionValue > 0) ? round($consumptionValue / $consumptionUnits, 2) : Tariff::getActivePrice();

        $message = str_replace(
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
                '{subscription_fee}',
                '{tanker_fee}',
                '{previous_balance}',
                '{collector}',
            ],
            [
                $name,
                $invoice->bill_number,
                number_format((float) (($invoice->total_amount ?? 0) + ($invoice->previous_balance ?? 0) - ($invoice->credit_applied ?? 0)), 2),
                $pdfLink,
                $invoice->billing_period_start ? $invoice->billing_period_start->format('Y-m-d') : '',
                $invoice->billing_period_end ? $invoice->billing_period_end->format('Y-m-d') : '',
                $invoice->meter->unique_code ?? '',
                $invoice->subscriber->square->name ?? '',
                $previousReading,
                $currentReading,
                $consumptionUnits,
                $unitPrice,
                number_format((float) $consumptionValue, 2),
                number_format((float) ($invoice->subscription_installment ?? 0), 2),
                number_format((float) ($invoice->tanker_charges ?? 0), 2),
                number_format((float) ($invoice->previous_balance ?? 0), 2),
                Auth::check() ? Auth::user()->name : 'إدارة المشروع',
            ],
            $template
        );

        // Handle conditional blocks
        $vars = [
            'subscription_fee' => (float) ($invoice->subscription_installment ?? 0),
            'tanker_fee' => (float) ($invoice->tanker_charges ?? 0),
            'previous_balance' => (float) ($invoice->previous_balance ?? 0),
        ];

        foreach ($vars as $key => $val) {
            if ($val > 0) {
                $message = preg_replace('/\{if:'.$key.'\}(.*?)\{\/if:'.$key.'\}/s', '$1', $message);
            } else {
                $message = preg_replace('/\{if:'.$key.'\}(.*?)\{\/if:'.$key.'\}/s', '', $message);
            }
        }

        return $message;
    }

    /**
     * إنتاج رابط إرسال رسالة واتساب لسند القبض (Click to Chat)
     */
    public function getPaymentMessageUrl(Payment $payment): ?string
    {
        $phone = $payment->meter?->tenant_phone ?? $payment->subscriber->phone;
        $formattedPhone = $this->formatPhoneNumber($phone);

        if (! $formattedPhone) {
            return null;
        }

        return 'https://wa.me/'.$formattedPhone.'?text='.rawurlencode($this->paymentMessage($payment));
    }

    /** نص رسالة تأكيد الدفع — يُستخدم أيضاً في إيصال السند للتطبيق (message_template). */
    public function paymentMessage(Payment $payment): string
    {
        $name = $payment->meter?->tenant_name ?? $payment->subscriber->name;
        $amount = number_format($payment->amount, 2);

        $defaultMsg = "أهلاً بك {name}،\nاستلمنا دفعتك بنجاح بموجب سند قبض رقم {receipt_number}.\nإجمالي المبلغ المدفوع: {amount} ريال.\nشكراً لتعاملك معنا.";
        $template = Setting::get('whatsapp_payment_message', $defaultMsg);

        $invoiceRemaining = 0;
        if ($payment->invoice) {
            $invoiceTotal = $payment->invoice->total_amount + $payment->invoice->previous_balance;
            $creditApplied = $payment->invoice->credit_applied ?? 0;
            $directPaidBefore = Payment::where('invoice_id', $payment->invoice_id)
                ->where('id', '<', $payment->id)
                ->sum('amount');
            $paidBefore = $directPaidBefore + $creditApplied;
            $dueBefore = $invoiceTotal - $paidBefore;
            $invoiceRemaining = $dueBefore - $payment->amount;
        }

        $message = str_replace(
            [
                '{name}',
                '{receipt_number}',
                '{amount}',
                '{invoice_remaining}',
                '{outstanding_balance}',
                '{credit_balance}',
                '{pdf_link}',
            ],
            [
                $name,
                $payment->receipt_number,
                $amount,
                number_format((float) $invoiceRemaining, 2),
                number_format((float) $payment->subscriber->getOutstandingBalance(), 2),
                number_format((float) $payment->subscriber->balance, 2),
                route('payments.print', $payment),
            ],
            $template
        );

        return $message;
    }

    /**
     * رفع ملف إلى خوادم ميتا والحصول على Media ID
     */
    public function uploadMedia(string $filePath, string $filename = 'document.pdf'): ?string
    {
        $token = Setting::get('whatsapp_access_token');
        $phoneId = Setting::get('whatsapp_phone_number_id');

        if (empty($token) || empty($phoneId)) {
            Log::error('WhatsApp API: Token or Phone ID is missing for Media Upload.');

            return null;
        }

        if (! file_exists($filePath)) {
            Log::error('WhatsApp API: File to upload does not exist: '.$filePath);

            return null;
        }

        try {
            $response = Http::withToken($token)
                ->attach('file', file_get_contents($filePath), $filename)
                ->post("{$this->baseUrl}/{$phoneId}/media", [
                    'messaging_product' => 'whatsapp',
                    'type' => 'application/pdf',
                ]);

            if ($response->successful() && isset($response->json()['id'])) {
                return $response->json()['id'];
            }

            Log::error('WhatsApp API Media Upload Error: '.$response->body());

            return null;
        } catch (\Exception $e) {
            Log::error('WhatsApp API Media Upload Exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * إرسال رسالة باستخدام Template عبر Meta API مع إمكانية إرفاق Document
     */
    public function sendTemplateMessage(string $toPhone, string $templateName, array $variables = [], ?string $mediaId = null, string $documentFilename = 'Document.pdf'): bool
    {
        $token = Setting::get('whatsapp_access_token');
        $phoneId = Setting::get('whatsapp_phone_number_id');

        if (empty($token) || empty($phoneId)) {
            Log::error('WhatsApp API: Token or Phone ID is missing.');

            return false;
        }

        $formattedPhone = $this->formatPhoneNumber($toPhone);
        if (! $formattedPhone) {
            Log::error('WhatsApp API: Invalid phone number format provided: '.$toPhone);

            return false;
        }

        $parameters = [];
        foreach ($variables as $variable) {
            $parameters[] = [
                'type' => 'text',
                'text' => (string) $variable,
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $formattedPhone,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => 'ar'],
                'components' => [],
            ],
        ];

        // إرفاق الملف كـ Header إذا توفر Media ID
        if ($mediaId) {
            $payload['template']['components'][] = [
                'type' => 'header',
                'parameters' => [
                    [
                        'type' => 'document',
                        'document' => [
                            'id' => $mediaId,
                            'filename' => $documentFilename,
                        ],
                    ],
                ],
            ];
        }

        // إرفاق المتغيرات كـ Body
        if (! empty($parameters)) {
            $payload['template']['components'][] = [
                'type' => 'body',
                'parameters' => $parameters,
            ];
        }

        try {
            $response = Http::withToken($token)
                ->post("{$this->baseUrl}/{$phoneId}/messages", $payload);

            if ($response->successful()) {
                return true;
            } else {
                Log::error('WhatsApp API Message Error: '.$response->body());

                return false;
            }
        } catch (\Exception $e) {
            Log::error('WhatsApp API Message Exception: '.$e->getMessage());

            return false;
        }
    }

    /**
     * إرسال رسالة وملف باستخدام الخادم المحلي (Unofficial API)
     */
    public function sendLocalApiMessage(string $toPhone, string $message, ?string $documentPath = null, string $filename = 'document.pdf'): bool
    {
        $formattedPhone = $this->formatPhoneNumber($toPhone);
        if (! $formattedPhone) {
            Log::error('Local WhatsApp API: Invalid phone number format provided: '.$toPhone);

            return false;
        }

        try {
            $request = Http::timeout(30);

            if ($documentPath && file_exists($documentPath)) {
                $request = $request->attach('file', file_get_contents($documentPath), $filename);
            }

            $response = $request->post('http://localhost:3000/api/send', [
                'phone' => $formattedPhone,
                'message' => $message,
            ]);

            if ($response->successful() && $response->json('success') === true) {
                return true;
            } else {
                Log::error('Local WhatsApp API Message Error: '.$response->body());

                return false;
            }
        } catch (\Exception $e) {
            Log::error('Local WhatsApp API Exception: '.$e->getMessage());

            return false;
        }
    }
}
