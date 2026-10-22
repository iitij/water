<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\Setting;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Actions\Action;

class WhatsAppSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';
    protected static ?string $navigationLabel = 'التنبيهات والرسائل';
    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';
    protected static ?string $title = 'إعدادات تنبيهات الواتساب';
    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.whats-app-settings';

    public ?array $data = [];

    // Local Server Status Properties
    public string $localServerStatus = 'offline';
    public ?string $localServerQr = null;
    public string $whatsappLogs = '';
    public string $queueLogs = '';

    protected function tailLog(string $filepath, int $lines = 15)
    {
        if (!file_exists($filepath)) {
            return 'جاري بدء التشغيل أو لا يوجد سجل بعد...';
        }
        $content = file($filepath);
        if ($content === false || empty($content)) return 'لا توجد بيانات.';
        return implode("", array_slice($content, -$lines));
    }

    public function checkLocalServerStatus()
    {
        $this->whatsappLogs = $this->tailLog(storage_path('logs/whatsapp.log'));
        $this->queueLogs = $this->tailLog(storage_path('logs/queue.log'));

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(2)->get('http://localhost:3000/api/status');
            if ($response->successful()) {
                $data = $response->json();
                if ($data['connected']) {
                    $this->localServerStatus = 'connected';
                    $this->localServerQr = null;
                } else if (!empty($data['qr'])) {
                    $this->localServerStatus = 'qr';
                    $this->localServerQr = $data['qr'];
                } else {
                    $this->localServerStatus = 'waiting';
                }
            } else {
                $this->localServerStatus = 'offline';
            }
        } catch (\Exception $e) {
            $this->localServerStatus = 'offline';
        }
    }

    public function startLocalServer()
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $vbsPath = base_path('start-whatsapp-hidden.vbs');
            pclose(popen("wscript \"$vbsPath\"", "r"));
        } else {
            exec("nohup node " . base_path('whatsapp-server/server.js') . " > /dev/null 2>&1 &");
        }

        Notification::make()
            ->title('جاري التشغيل في الخلفية بصمت')
            ->body('لن تظهر أي نافذة سوداء بعد الآن. انتظر لحظات...')
            ->success()
            ->send();
            
        $this->localServerStatus = 'waiting';
    }

    public function stopLocalServer()
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec("taskkill /F /IM node.exe");
        } else {
            exec("pkill -f 'node.*whatsapp-server/server.js'");
        }
        
        Notification::make()
            ->title('تم إيقاف الخادم')
            ->warning()
            ->send();
            
        $this->localServerStatus = 'offline';
    }

    public function mount(): void
    {
        $defaultInvoiceMsg = "أهلاً بك {name}،\nتم إصدار فاتورتك الجديدة رقم {bill_number} للفترة من {from_date} إلى {to_date}.\n\nتفاصيل القراءة:\nالقراءة السابقة: {previous_reading}\nالقراءة الحالية: {current_reading}\nوحدات الاستهلاك: {consumption_units}\nقيمة الاستهلاك: {consumption_value} ريال\nالمتأخرات: {previous_balance} ريال\nالرصيد المخصوم: {credit_applied} ريال\n\nالمبلغ الإجمالي المستحق: {amount} ريال\n\nللاطلاع على الفاتورة: {pdf_link}\nشكراً لتعاملك معنا.";
        $defaultPaymentMsg = "أهلاً بك {name}،\nاستلمنا دفعتك بنجاح بموجب سند قبض رقم {receipt_number}.\nإجمالي المبلغ المدفوع: {amount} ريال.\nالمتبقي من الفاتورة: {invoice_remaining} ريال.\nإجمالي المتبقي للحساب: {outstanding_balance} ريال.\nالرصيد الدائن: {credit_balance} ريال.\n\nللاطلاع على السند: {pdf_link}\nشكراً لتعاملك معنا.";

        $this->form->fill([
            'whatsapp_enabled' => Setting::get('whatsapp_enabled', false),
            'auto_send_whatsapp_invoice' => Setting::get('auto_send_whatsapp_invoice', false),
            'auto_send_whatsapp_payment' => Setting::get('auto_send_whatsapp_payment', false),
            'whatsapp_method' => Setting::get('whatsapp_method', 'click_to_chat'),
            
            // Meta API Settings
            'whatsapp_access_token' => Setting::get('whatsapp_access_token', ''),
            'whatsapp_phone_number_id' => Setting::get('whatsapp_phone_number_id', ''),
            'whatsapp_invoice_template' => Setting::get('whatsapp_invoice_template', 'new_invoice_template'),
            'whatsapp_payment_template' => Setting::get('whatsapp_payment_template', 'payment_receipt_template'),

            // Click to Chat Settings
            'whatsapp_invoice_message' => Setting::get('whatsapp_invoice_message', $defaultInvoiceMsg),
            'whatsapp_payment_message' => Setting::get('whatsapp_payment_message', $defaultPaymentMsg),
        ]);

        $this->checkLocalServerStatus();
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('الضبط الأساسي')
                    ->description('تفعيل وإعداد طريقة إرسال الواتساب')
                    ->schema([
                        Toggle::make('whatsapp_enabled')
                            ->label('تفعيل الخدمة بشكل عام')
                            ->live(),

                        Toggle::make('auto_send_whatsapp_invoice')
                            ->label('إرسال الفاتورة تلقائياً عند الإصدار')
                            ->helperText('سيتم إرسال رسالة الواتساب مع الفاتورة (PDF) تلقائياً بمجرد إصدارها في النظام.')
                            ->visible(fn ($get) => $get('whatsapp_enabled') && in_array($get('whatsapp_method'), ['meta_api', 'local_api'])),

                        Toggle::make('auto_send_whatsapp_payment')
                            ->label('إرسال سند القبض تلقائياً عند السداد')
                            ->helperText('سيتم إرسال رسالة الواتساب مع سند القبض (PDF) تلقائياً بمجرد تسجيل عملية الدفع.')
                            ->visible(fn ($get) => $get('whatsapp_enabled') && in_array($get('whatsapp_method'), ['meta_api', 'local_api'])),

                        Select::make('whatsapp_method')
                            ->label('طريقة الإرسال المعتمدة')
                            ->options([
                                'click_to_chat' => 'الرابط المباشر (مجانأ وبدون قيود)',
                                'meta_api' => 'مباشر عبر Meta Cloud API (يرفق ملف PDF)',
                                'local_api' => 'الخادم المحلي (مجاني 100% + يرفق PDF)',
                            ])
                            ->default('click_to_chat')
                            ->required()
                            ->live()
                            ->visible(fn ($get) => $get('whatsapp_enabled')),
                    ]),

                Section::make('حالة الخادم المحلي (Local API)')
                    ->description('يجب أن يكون الخادم المصغر قيد التشغيل في جهازك ليتمكن من الإرسال.')
                    ->schema([
                        \Filament\Forms\Components\ViewField::make('qr_view')
                            ->view('filament.settings.qr')
                            ->label('')
                    ])
                    ->visible(fn ($get) => $get('whatsapp_enabled') && $get('whatsapp_method') === 'local_api'),

                Section::make('إعدادات الرابط المباشر أو الخادم المحلي')
                    ->description('قم بكتابة النص الذي سيظهر تلقائياً في تطبيق الواتساب. يمكنك استخدام المتغيرات المعروضة لتعويضها بالبيانات الحقيقية.')
                    ->schema([
                        \Filament\Forms\Components\Textarea::make('whatsapp_invoice_message')
                            ->label('نص رسالة الفاتورة')
                            ->helperText('المتغيرات المتاحة: {name}, {bill_number}, {from_date}, {to_date}, {previous_reading}, {current_reading}, {consumption_units}, {consumption_value}, {previous_balance}, {credit_applied}, {amount}, {pdf_link}')
                            ->required()
                            ->rows(5),

                        \Filament\Forms\Components\Textarea::make('whatsapp_payment_message')
                            ->label('نص رسالة سند القبض')
                            ->helperText('المتغيرات المتاحة: {name}, {receipt_number}, {amount}, {invoice_remaining}, {outstanding_balance}, {credit_balance}, {pdf_link}')
                            ->required()
                            ->rows(5),
                    ])
                    ->visible(fn ($get) => $get('whatsapp_enabled') && in_array($get('whatsapp_method'), ['click_to_chat', 'local_api'])),

                Section::make('إعدادات Meta Cloud API')
                    ->description('إعدادات الربط المباشر مع سيرفرات واتساب لإرسال الفاتورة آلياً كملف PDF')
                    ->schema([
                        TextInput::make('whatsapp_phone_number_id')
                            ->label('Phone Number ID (معرف رقم الهاتف)')
                            ->password()
                            ->revealable()
                            ->required(fn ($get) => $get('whatsapp_method') === 'meta_api'),

                        TextInput::make('whatsapp_access_token')
                            ->label('Access Token (رمز الوصول السري)')
                            ->password()
                            ->revealable()
                            ->required(fn ($get) => $get('whatsapp_method') === 'meta_api'),

                        TextInput::make('whatsapp_invoice_template')
                            ->label('قالب الفاتورة (Invoice Template)')
                            ->default('new_invoice_template')
                            ->helperText('يجب أن يحتوي القالب في ميتا على Header من نوع Document، و Body بثلاث متغيرات.')
                            ->required(fn ($get) => $get('whatsapp_method') === 'meta_api'),

                        \Filament\Forms\Components\Placeholder::make('invoice_template_guide')
                            ->label('النص المقترح لقالب الفاتورة (لنسخه في Meta)')
                            ->content(new \Illuminate\Support\HtmlString('<div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg text-sm font-mono whitespace-pre-wrap" dir="rtl">أهلاً بك {{1}}،
تم إصدار فاتورتك الجديدة رقم {{2}}.
المبلغ الإجمالي المطلوب سداده هو: {{3}} ريال.
يرجى الاطلاع على الفاتورة المرفقة (PDF).
شكراً لتعاملك معنا.</div>')),

                        TextInput::make('whatsapp_payment_template')
                            ->label('قالب سند القبض (Payment Template)')
                            ->default('payment_receipt_template')
                            ->helperText('يجب أن يحتوي القالب في ميتا على Header من نوع Document، و Body بثلاث متغيرات.')
                            ->required(fn ($get) => $get('whatsapp_method') === 'meta_api'),

                        \Filament\Forms\Components\Placeholder::make('payment_template_guide')
                            ->label('النص المقترح لقالب السند (لنسخه في Meta)')
                            ->content(new \Illuminate\Support\HtmlString('<div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg text-sm font-mono whitespace-pre-wrap" dir="rtl">أهلاً بك {{1}}،
استلمنا دفعتك بنجاح بموجب سند قبض رقم {{2}}.
إجمالي المبلغ المدفوع: {{3}} ريال.
يرجى الاطلاع على سند القبض المرفق (PDF).
شكراً لتعاملك معنا.</div>')),
                    ])
                    ->columns(2)
                    ->visible(fn ($get) => $get('whatsapp_enabled') && $get('whatsapp_method') === 'meta_api'),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('حفظ الإعدادات')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::set('whatsapp_enabled', $data['whatsapp_enabled']);
        Setting::set('auto_send_whatsapp_invoice', $data['auto_send_whatsapp_invoice'] ?? false);
        Setting::set('auto_send_whatsapp_payment', $data['auto_send_whatsapp_payment'] ?? false);
        Setting::set('whatsapp_method', $data['whatsapp_method'] ?? 'click_to_chat');

        // Click to chat settings
        Setting::set('whatsapp_invoice_message', $data['whatsapp_invoice_message'] ?? '');
        Setting::set('whatsapp_payment_message', $data['whatsapp_payment_message'] ?? '');

        // Meta API Settings
        Setting::set('whatsapp_access_token', $data['whatsapp_access_token'] ?? '');
        Setting::set('whatsapp_phone_number_id', $data['whatsapp_phone_number_id'] ?? '');
        Setting::set('whatsapp_invoice_template', $data['whatsapp_invoice_template'] ?? '');
        Setting::set('whatsapp_payment_template', $data['whatsapp_payment_template'] ?? '');

        Notification::make()
            ->success()
            ->title('تم الحفظ')
            ->body('تم حفظ إعدادات الواتساب بنجاح.')
            ->send();
    }
}
