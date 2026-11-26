<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;

class ReadingSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';

    protected static ?string $title = 'مواعيد زيارات قراءة العدادات';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.reading-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'reading_period' => Setting::get('reading_period', 'monthly'),
            'reading_day' => Setting::get('reading_day', 'last_day'),
            'auto_issue_invoice_on_reading' => Setting::get('auto_issue_invoice_on_reading', '0') == '1',
            'grace_period' => Setting::get('grace_period', '14'),
            'strict_overdue_on_new_invoice' => Setting::get('strict_overdue_on_new_invoice', '0') == '1',
        ]);
    }

    public function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Toggle::make('auto_issue_invoice_on_reading')
                    ->label('إصدار الفاتورة تلقائياً عند إدخال القراءة')
                    ->helperText('إذا تم تفعيل هذا الخيار، سيتم إصدار فاتورة للعداد تلقائياً بمجرد إدخال قراءة جديدة له.'),

                Select::make('reading_period')
                    ->label('المدة المتوقعة بين زيارات القراءة')
                    ->options([
                        'weekly' => 'أسبوعية (كل 7 أيام)',
                        'ten_days' => 'كل 10 أيام',
                        'half_monthly' => 'نصف شهرية (كل 15 يوم)',
                        'monthly' => 'شهرية',
                    ])
                    ->required()
                    ->live()
                    ->native(false)
                    ->helperText('هذا إعداد لتخطيط زيارات قارئ العداد فقط؛ فترة الفاتورة تُحدد من تاريخ القراءة السابقة إلى تاريخ القراءة الحالية.'),

                Select::make('reading_day')
                    ->label('يوم الزيارة المخطط له')
                    ->options(function () {
                        $days = [];
                        for ($i = 20; $i <= 28; $i++) {
                            $days[$i] = 'يوم ' . $i;
                        }
                        $days['last_day'] = 'آخر يوم في الشهر';
                        return $days;
                    })
                    ->visible(fn ($get) => $get('reading_period') === 'monthly')
                    ->required()
                    ->native(false)
                    ->helperText('موعد زيارة متوقع لا يغيّر تواريخ الاستهلاك الفعلية أو الفواتير الصادرة.'),
                \Filament\Forms\Components\TextInput::make('grace_period')
                    ->label('مدة السماح (أيام)')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(365)
                    ->default(14)
                    ->required()
                    ->helperText('عدد الأيام المسموح بها بعد إصدار الفاتورة قبل أن تتحول حالتها إلى "متأخرة". الافتراضي هو 14 يوماً.'),

                \Filament\Forms\Components\Toggle::make('strict_overdue_on_new_invoice')
                    ->label('اعتبار الفاتورة السابقة متأخرة فور إصدار فاتورة جديدة')
                    ->helperText('إذا تم تفعيل هذا الخيار، سيتم اعتبار الفاتورة السابقة "متأخرة" بمجرد إصدار فاتورة جديدة للمشترك، بغض النظر عن مدة السماح المتبقية.'),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        Setting::set('reading_period', $data['reading_period'], 'دورة القراءات');
        Setting::set('reading_day', $data['reading_day'] ?? 'last_day', 'يوم القراءة');
        Setting::set('auto_issue_invoice_on_reading', $data['auto_issue_invoice_on_reading'] ? '1' : '0', 'إصدار الفاتورة تلقائياً عند إدخال القراءة');
        Setting::set('grace_period', $data['grace_period'], 'مدة السماح (أيام)');
        Setting::set('strict_overdue_on_new_invoice', $data['strict_overdue_on_new_invoice'] ? '1' : '0', 'اعتبار الفاتورة متأخرة عند الإصدار الجديد');

        Notification::make()
            ->success()
            ->title('تم الحفظ')
            ->body('تم حفظ إعدادات دورة القراءات بنجاح.')
            ->send();
    }
}
