<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

class ReceiptSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';

    protected static ?string $title = 'إعدادات وتصميم سند القبض';

    protected static ?int $navigationSort = 13;

    protected string $view = 'filament.pages.invoice-settings'; // Reusing the same blank view that outputs the form

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            // Shared Header
            'invoice_project_name_ar' => Setting::get('invoice_project_name_ar', 'مشروع مياه الوشاح'),
            'invoice_project_name_en' => Setting::get('invoice_project_name_en', 'Washah Water Project'),
            'invoice_address_ar' => Setting::get('invoice_address_ar', 'حارة الفوارس - سعوان'),
            'invoice_address_en' => Setting::get('invoice_address_en', 'Harat Al-Fawaris - Sa\'wan'),
            'invoice_phone_1' => Setting::get('invoice_phone_1', '1234567890'),
            'invoice_phone_2' => Setting::get('invoice_phone_2', '1234567890'),
            'invoice_logo' => Setting::get('invoice_logo', null),
            'invoice_watermark' => Setting::get('invoice_watermark', null),

            // Titles
            'rect_title_main' => Setting::get('rect_title_main', 'سند قبض'),
            'rect_show_title_subscriber' => Setting::get('rect_show_title_subscriber', '1') == '1',
            'rect_title_subscriber' => Setting::get('rect_title_subscriber', 'بيانات المشترك'),
            'rect_show_title_payment' => Setting::get('rect_show_title_payment', '1') == '1',
            'rect_title_payment' => Setting::get('rect_title_payment', 'بيانات الدفع'),

            // Receipt Info
            'rect_show_receipt_number' => Setting::get('rect_show_receipt_number', '1') == '1',
            'rect_lbl_receipt_number' => Setting::get('rect_lbl_receipt_number', 'رقم السند'),
            'rect_show_receipt_date' => Setting::get('rect_show_receipt_date', '1') == '1',
            'rect_lbl_receipt_date' => Setting::get('rect_lbl_receipt_date', 'تاريخ السند'),

            // Subscriber Info
            'rect_show_sub_name' => Setting::get('rect_show_sub_name', '1') == '1',
            'rect_lbl_sub_name' => Setting::get('rect_lbl_sub_name', 'اسم المشترك'),
            'rect_show_sub_phone' => Setting::get('rect_show_sub_phone', '1') == '1',
            'rect_lbl_sub_phone' => Setting::get('rect_lbl_sub_phone', 'الهاتف'),
            'rect_show_square' => Setting::get('rect_show_square', '1') == '1',
            'rect_lbl_square' => Setting::get('rect_lbl_square', 'المربع السكني'),
            'rect_show_account_no' => Setting::get('rect_show_account_no', '1') == '1',
            'rect_lbl_account_no' => Setting::get('rect_lbl_account_no', 'رقم الحساب'),
            
            // Tenant Row

            // Payment Info
            'rect_show_payment_method' => Setting::get('rect_show_payment_method', '1') == '1',
            'rect_lbl_payment_method' => Setting::get('rect_lbl_payment_method', 'طريقة الدفع'),
            'rect_show_linked_invoice' => Setting::get('rect_show_linked_invoice', '1') == '1',
            'rect_lbl_linked_invoice' => Setting::get('rect_lbl_linked_invoice', 'الفاتورة المرتبطة'),
            'rect_show_reference' => Setting::get('rect_show_reference', '1') == '1',
            'rect_lbl_reference' => Setting::get('rect_lbl_reference', 'رقم الحوالة / الشيك'),

            // Amount Boxes
            'rect_show_invoice_total' => Setting::get('rect_show_invoice_total', '1') == '1',
            'rect_lbl_invoice_total' => Setting::get('rect_lbl_invoice_total', 'إجمالي الفاتورة'),
            'rect_show_prev_payments' => Setting::get('rect_show_prev_payments', '1') == '1',
            'rect_lbl_prev_payments' => Setting::get('rect_lbl_prev_payments', 'مدفوعات سابقة'),
            'rect_show_amount_received' => Setting::get('rect_show_amount_received', '1') == '1',
            'rect_lbl_amount_received' => Setting::get('rect_lbl_amount_received', 'المبلغ المستلم'),
            'rect_show_remaining_invoice' => Setting::get('rect_show_remaining_invoice', '1') == '1',
            'rect_lbl_remaining_invoice' => Setting::get('rect_lbl_remaining_invoice', 'المتبقي من الفاتورة'),
            'rect_show_outstanding_total' => Setting::get('rect_show_outstanding_total', '1') == '1',
            'rect_lbl_outstanding_total' => Setting::get('rect_lbl_outstanding_total', 'إجمالي المتبقي للحساب'),
            'rect_show_credit_balance' => Setting::get('rect_show_credit_balance', '1') == '1',
            'rect_lbl_credit_balance' => Setting::get('rect_lbl_credit_balance', 'رصيد دائن للمشترك'),

            'rect_show_reason' => Setting::get('rect_show_reason', '1') == '1',
            'rect_lbl_reason' => Setting::get('rect_lbl_reason', 'وذلك مقابل'),

            // Amount in words
            'rect_show_amount_in_words' => Setting::get('rect_show_amount_in_words', '1') == '1',
            'rect_lbl_amount_in_words' => Setting::get('rect_lbl_amount_in_words', 'المبلغ المستلم كتابتا'),
            'rect_amount_words_suffix' => Setting::get('rect_amount_words_suffix', 'ريال يمني لا غير'),

            // Footer
            'rect_show_collector_sig' => Setting::get('rect_show_collector_sig', '1') == '1',
            'rect_lbl_collector_sig' => Setting::get('rect_lbl_collector_sig', 'توقيع المحصل'),
            'rect_show_receiver_sig' => Setting::get('rect_show_receiver_sig', '1') == '1',
            'rect_lbl_receiver_sig' => Setting::get('rect_lbl_receiver_sig', 'توقيع المستلم'),
            'rect_show_notes' => Setting::get('rect_show_notes', '1') == '1',
            'rect_lbl_notes' => Setting::get('rect_lbl_notes', 'ملاحظات'),
        ]);
    }

    public function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form
            ->schema([
                Tabs::make('ReceiptSettingsTabs')
                    ->tabs([
                        Tab::make('الأساسيات (مشتركة مع الفاتورة)')
                            ->icon('heroicon-o-building-office')
                            ->schema([
                                Section::make('النصوص الأساسية')
                                    ->schema([
                                        TextInput::make('invoice_project_name_ar')->label('اسم المشروع (عربي)'),
                                        TextInput::make('invoice_project_name_en')->label('اسم المشروع (إنجليزي)'),
                                        TextInput::make('invoice_address_ar')->label('العنوان (عربي)'),
                                        TextInput::make('invoice_address_en')->label('العنوان (إنجليزي)'),
                                        TextInput::make('invoice_phone_1')->label('الهاتف 1'),
                                        TextInput::make('invoice_phone_2')->label('الهاتف 2'),
                                    ])->columns(2),

                                Section::make('الصور والشعارات')
                                    ->schema([
                                        FileUpload::make('invoice_logo')
                                            ->label('شعار المشروع (الرئيسي)')
                                            ->image()
                                            ->directory('settings/invoices'),
                                        FileUpload::make('invoice_watermark')
                                            ->label('العلامة المائية')
                                            ->image()
                                            ->directory('settings/invoices'),
                                    ])->columns(2),
                            ]),

                        Tab::make('عناوين السند')
                            ->icon('heroicon-o-bars-3')
                            ->schema([
                                TextInput::make('rect_title_main')->label('عنوان السند الرئيسي')->required(),
                                $this->buildFieldConfig('عنوان قسم بيانات المشترك', 'rect_show_title_subscriber', 'rect_title_subscriber'),
                                $this->buildFieldConfig('عنوان قسم بيانات الدفع', 'rect_show_title_payment', 'rect_title_payment'),
                            ]),

                        Tab::make('حقول السند والمشترك')
                            ->icon('heroicon-o-user')
                            ->schema([
                                $this->buildFieldConfig('رقم السند', 'rect_show_receipt_number', 'rect_lbl_receipt_number'),
                                $this->buildFieldConfig('تاريخ السند', 'rect_show_receipt_date', 'rect_lbl_receipt_date'),
                                
                                $this->buildFieldConfig('اسم المشترك', 'rect_show_sub_name', 'rect_lbl_sub_name'),
                                $this->buildFieldConfig('الهاتف', 'rect_show_sub_phone', 'rect_lbl_sub_phone'),
                                $this->buildFieldConfig('المربع السكني', 'rect_show_square', 'rect_lbl_square'),
                                $this->buildFieldConfig('رقم الحساب', 'rect_show_account_no', 'rect_lbl_account_no'),

                            ]),

                        Tab::make('بيانات الدفع والمبالغ')
                            ->icon('heroicon-o-currency-dollar')
                            ->schema([
                                $this->buildFieldConfig('طريقة الدفع', 'rect_show_payment_method', 'rect_lbl_payment_method'),
                                $this->buildFieldConfig('الفاتورة المرتبطة', 'rect_show_linked_invoice', 'rect_lbl_linked_invoice'),
                                $this->buildFieldConfig('رقم الحوالة / الشيك', 'rect_show_reference', 'rect_lbl_reference'),
                                
                                $this->buildFieldConfig('إجمالي الفاتورة', 'rect_show_invoice_total', 'rect_lbl_invoice_total'),
                                $this->buildFieldConfig('مدفوعات سابقة', 'rect_show_prev_payments', 'rect_lbl_prev_payments'),
                                $this->buildFieldConfig('المبلغ المستلم', 'rect_show_amount_received', 'rect_lbl_amount_received'),
                                $this->buildFieldConfig('المتبقي من الفاتورة', 'rect_show_remaining_invoice', 'rect_lbl_remaining_invoice'),
                                $this->buildFieldConfig('إجمالي المتبقي للحساب', 'rect_show_outstanding_total', 'rect_lbl_outstanding_total'),
                                $this->buildFieldConfig('رصيد دائن للمشترك', 'rect_show_credit_balance', 'rect_lbl_credit_balance'),
                                $this->buildFieldConfig('وذلك مقابل', 'rect_show_reason', 'rect_lbl_reason'),
                                
                                Section::make('المبلغ كتابتاً')
                                    ->schema([
                                        Grid::make(3)->schema([
                                            Toggle::make('rect_show_amount_in_words')->label('عرض الحقل في السند')->inline(false)->default(true),
                                            TextInput::make('rect_lbl_amount_in_words')->label('النص البديل (المسمى)')->default('المبلغ المستلم كتابتا')->required(),
                                            TextInput::make('rect_amount_words_suffix')->label('اللاحقة (مثل: ريال يمني لا غير)')->default('ريال يمني لا غير')->required(),
                                        ]),
                                    ])->collapsible()->compact(),
                            ]),

                        Tab::make('التذييل والملاحظات')
                            ->icon('heroicon-o-document-arrow-down')
                            ->schema([
                                $this->buildFieldConfig('ملاحظات', 'rect_show_notes', 'rect_lbl_notes'),
                                $this->buildFieldConfig('توقيع المحصل', 'rect_show_collector_sig', 'rect_lbl_collector_sig'),
                                $this->buildFieldConfig('توقيع المستلم', 'rect_show_receiver_sig', 'rect_lbl_receiver_sig'),
                            ]),
                    ])->columnSpan('full')
            ])
            ->statePath('data');
    }

    private function buildFieldConfig(string $title, string $showField, string $lblField)
    {
        return Section::make($title)
            ->schema([
                Grid::make(2)->schema([
                    Toggle::make($showField)->label('عرض الحقل في السند')->inline(false),
                    TextInput::make($lblField)->label('النص البديل (المسمى)')->required(),
                ]),
            ])->collapsible()->compact();
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            Setting::set($key, $value, 'إعدادات سند القبض');
        }

        Notification::make()
            ->success()
            ->title('تم الحفظ')
            ->body('تم حفظ إعدادات وتصميم سند القبض بنجاح.')
            ->send();
    }
}
