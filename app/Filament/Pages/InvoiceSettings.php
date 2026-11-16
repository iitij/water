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

class InvoiceSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';

    protected static ?string $title = 'إعدادات وتصميم الفاتورة';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.pages.invoice-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            // Basic
            'invoice_project_name_ar' => Setting::get('invoice_project_name_ar', 'مشروع مياه الوشاح'),
            'invoice_project_name_en' => Setting::get('invoice_project_name_en', 'Washah Water Project'),
            'invoice_address_ar' => Setting::get('invoice_address_ar', 'حارة الفوارس - سعوان'),
            'invoice_address_en' => Setting::get('invoice_address_en', 'Harat Al-Fawaris - Sa\'wan'),
            'invoice_phone_1' => Setting::get('invoice_phone_1', '1234567890'),
            'invoice_phone_2' => Setting::get('invoice_phone_2', '1234567890'),
            'invoice_collector_name' => Setting::get('invoice_collector_name', 'علي الوشاح'),
            'invoice_logo' => Setting::get('invoice_logo', null),
            'site_favicon' => Setting::get('site_favicon', null),
            'invoice_watermark' => Setting::get('invoice_watermark', null),

            // Titles
            'inv_title_main' => Setting::get('inv_title_main', 'فاتورة استهلاك مياه'),
            'inv_title_subscriber' => Setting::get('inv_title_subscriber', 'بيانات المشترك'),
            'inv_title_consumption' => Setting::get('inv_title_consumption', 'تفاصيل الاستهلاك'),
            'inv_title_total' => Setting::get('inv_title_total', 'المبلغ المستحق'),

            // Subscriber Row 1
            'inv_show_bill_number' => Setting::get('inv_show_bill_number', '1') == '1',
            'inv_lbl_bill_number' => Setting::get('inv_lbl_bill_number', 'رقم الفاتورة'),
            'inv_show_date_from' => Setting::get('inv_show_date_from', '1') == '1',
            'inv_lbl_date_from' => Setting::get('inv_lbl_date_from', 'من تاريخ'),
            'inv_show_date_to' => Setting::get('inv_show_date_to', '1') == '1',
            'inv_lbl_date_to' => Setting::get('inv_lbl_date_to', 'إلى تاريخ'),

            // Subscriber Row 2
            'inv_show_sub_name' => Setting::get('inv_show_sub_name', '1') == '1',
            'inv_lbl_sub_name' => Setting::get('inv_lbl_sub_name', 'اسم المشترك'),
            'inv_show_meter_no' => Setting::get('inv_show_meter_no', '1') == '1',
            'inv_lbl_meter_no' => Setting::get('inv_lbl_meter_no', 'رقم العداد'),
            'inv_show_sub_phone' => Setting::get('inv_show_sub_phone', '1') == '1',
            'inv_lbl_sub_phone' => Setting::get('inv_lbl_sub_phone', 'الهاتف'),

            // Subscriber Row 3
            'inv_show_square' => Setting::get('inv_show_square', '1') == '1',
            'inv_lbl_square' => Setting::get('inv_lbl_square', 'المربع'),
            'inv_show_address' => Setting::get('inv_show_address', '1') == '1',
            'inv_lbl_address' => Setting::get('inv_lbl_address', 'العنوان'),
            'inv_show_account_no' => Setting::get('inv_show_account_no', '1') == '1',
            'inv_lbl_account_no' => Setting::get('inv_lbl_account_no', 'رقم الحساب'),

            // Tenant Row

            // Consumption Table
            'inv_show_prev_read' => Setting::get('inv_show_prev_read', '1') == '1',
            'inv_lbl_prev_read' => Setting::get('inv_lbl_prev_read', 'القراءة السابقة'),
            'inv_show_curr_read' => Setting::get('inv_show_curr_read', '1') == '1',
            'inv_lbl_curr_read' => Setting::get('inv_lbl_curr_read', 'القراءة الحالية'),
            'inv_show_units' => Setting::get('inv_show_units', '1') == '1',
            'inv_lbl_units' => Setting::get('inv_lbl_units', 'وحدات الاستهلاك'),
            'inv_show_unit_price' => Setting::get('inv_show_unit_price', '1') == '1',
            'inv_lbl_unit_price' => Setting::get('inv_lbl_unit_price', 'سعر الوحدة'),
            'inv_show_cons_value' => Setting::get('inv_show_cons_value', '1') == '1',
            'inv_lbl_cons_value' => Setting::get('inv_lbl_cons_value', 'قيمة الاستهلاك'),
            'inv_show_arrears' => Setting::get('inv_show_arrears', '1') == '1',
            'inv_lbl_arrears' => Setting::get('inv_lbl_arrears', 'المتأخرات'),
            'inv_show_credit_applied' => Setting::get('inv_show_credit_applied', '1') == '1',
            'inv_lbl_credit_applied' => Setting::get('inv_lbl_credit_applied', 'رصيد مخصوم'),

            // Footer
            'inv_show_collector' => Setting::get('inv_show_collector', '1') == '1',
            'inv_lbl_collector' => Setting::get('inv_lbl_collector', 'المحصل'),
            'inv_show_signature' => Setting::get('inv_show_signature', '1') == '1',
            'inv_lbl_signature' => Setting::get('inv_lbl_signature', 'توقيع المشترك'),
            'inv_show_thank_you' => Setting::get('inv_show_thank_you', '1') == '1',
            'inv_lbl_thank_you' => Setting::get('inv_lbl_thank_you', 'شكراً لدفعكم في الموعد / Thank you for your timely payment'),
            'inv_lbl_notes' => Setting::get('inv_lbl_notes', 'الملاحظات'),

            // Subscription Invoice
        ]);
    }

    public function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form
            ->schema([
                Tabs::make('InvoiceSettingsTabs')
                    ->tabs([
                        Tab::make('الأساسيات والشعارات')
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
                                        TextInput::make('invoice_collector_name')->label('اسم المحصل الافتراضي'),
                                    ])->columns(2),

                                Section::make('الصور والشعارات')
                                    ->schema([
                                        FileUpload::make('invoice_logo')
                                            ->label('شعار المشروع (الرئيسي)')
                                            ->image()
                                            ->directory('settings/invoices')
                                            ->columnSpan(1),
                                        FileUpload::make('site_favicon')
                                            ->label('أيقونة الموقع (Favicon)')
                                            ->image()
                                            ->directory('settings/invoices')
                                            ->columnSpan(1),
                                        FileUpload::make('invoice_watermark')
                                            ->label('العلامة المائية')
                                            ->image()
                                            ->directory('settings/invoices')
                                            ->helperText('تظهر كخلفية شفافة خلف جداول الفاتورة.'),
                                    ])->columns(2),
                            ]),

                        Tab::make('عناوين الأقسام')
                            ->icon('heroicon-o-bars-3')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextInput::make('inv_title_main')->label('عنوان الفاتورة الرئيسي')->required(),
                                    TextInput::make('inv_title_subscriber')->label('عنوان قسم بيانات المشترك')->required(),
                                    TextInput::make('inv_title_consumption')->label('عنوان قسم تفاصيل الاستهلاك')->required(),
                                    TextInput::make('inv_title_total')->label('نص إجمالي الفاتورة')->required(),
                                ]),
                            ]),

                        Tab::make('حقول المشترك والفاتورة')
                            ->icon('heroicon-o-user')
                            ->schema([
                                $this->buildFieldConfig('رقم الفاتورة', 'inv_show_bill_number', 'inv_lbl_bill_number'),
                                $this->buildFieldConfig('من تاريخ', 'inv_show_date_from', 'inv_lbl_date_from'),
                                $this->buildFieldConfig('إلى تاريخ', 'inv_show_date_to', 'inv_lbl_date_to'),
                                
                                $this->buildFieldConfig('اسم المشترك', 'inv_show_sub_name', 'inv_lbl_sub_name'),
                                $this->buildFieldConfig('رقم العداد', 'inv_show_meter_no', 'inv_lbl_meter_no'),
                                $this->buildFieldConfig('الهاتف', 'inv_show_sub_phone', 'inv_lbl_sub_phone'),

                                $this->buildFieldConfig('المربع', 'inv_show_square', 'inv_lbl_square'),
                                $this->buildFieldConfig('العنوان', 'inv_show_address', 'inv_lbl_address'),
                                $this->buildFieldConfig('رقم الحساب', 'inv_show_account_no', 'inv_lbl_account_no'),

                            ]),

                        Tabs\Tab::make('فاتورة العداد (الاستهلاك والمبالغ)')
                            ->icon('heroicon-o-table-cells')
                            ->schema([
                                $this->buildFieldConfig('القراءة السابقة', 'inv_show_prev_read', 'inv_lbl_prev_read'),
                                $this->buildFieldConfig('القراءة الحالية', 'inv_show_curr_read', 'inv_lbl_curr_read'),
                                $this->buildFieldConfig('وحدات الاستهلاك', 'inv_show_units', 'inv_lbl_units'),
                                $this->buildFieldConfig('سعر الوحدة', 'inv_show_unit_price', 'inv_lbl_unit_price'),
                                $this->buildFieldConfig('قيمة الاستهلاك', 'inv_show_cons_value', 'inv_lbl_cons_value'),
                                $this->buildFieldConfig('المتأخرات', 'inv_show_arrears', 'inv_lbl_arrears'),
                                $this->buildFieldConfig('الرصيد المخصوم', 'inv_show_credit_applied', 'inv_lbl_credit_applied'),
                            ]),


                        Tab::make('تذييل الفاتورة')
                            ->icon('heroicon-o-document-arrow-down')
                            ->schema([
                                $this->buildFieldConfig('المحصل', 'inv_show_collector', 'inv_lbl_collector'),
                                $this->buildFieldConfig('توقيع المشترك', 'inv_show_signature', 'inv_lbl_signature'),
                                $this->buildFieldConfig('رسالة الشكر', 'inv_show_thank_you', 'inv_lbl_thank_you'),
                                $this->buildFieldConfig('الملاحظات', 'inv_show_notes', 'inv_lbl_notes'),
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
                    Toggle::make($showField)->label('عرض الحقل في الفاتورة')->inline(false),
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
            Setting::set($key, $value, 'إعدادات الفاتورة المتقدمة');
        }

        Notification::make()
            ->success()
            ->title('تم الحفظ')
            ->body('تم حفظ إعدادات وتصميم الفاتورة بنجاح.')
            ->send();
    }
}
