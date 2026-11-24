<?php

namespace App\Filament\Pages;

use App\Support\BackupControl;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BackupSettings extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-circle-stack';
    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';
    protected static ?string $title = 'إعدادات النسخ الاحتياطي';
    protected static ?string $slug = 'backup-settings';
    protected static ?int $navigationSort = 15;
    protected string $view = 'filament.pages.backup-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(app(BackupControl::class)->policy());
    }

    public function form(Schema $schema): Schema
    {
        $times = [];
        for ($i = 0; $i < 1440; $i += 5) {
            $value = sprintf('%02d:%02d', intdiv($i, 60), $i % 60);
            $times[$value] = $value;
        }

        return $schema->components([
            Section::make('الجدولة التلقائية')->schema([
                Toggle::make('enabled')->label('تفعيل النسخ الاحتياطي التلقائي')->required(),
                Select::make('interval_hours')->label('تكرار النسخ')
                    ->options([6 => 'كل 6 ساعات', 12 => 'كل 12 ساعة', 24 => 'يومياً', 48 => 'كل يومين',
                        72 => 'كل 3 أيام', 168 => 'أسبوعياً', 336 => 'كل أسبوعين', 720 => 'كل 30 يوماً'])
                    ->required()->native(false)
                    ->helperText('تبدأ الدورة الجديدة من أول موعد قادم عند تغيير التكرار أو الساعة.'),
                Select::make('time')->label('موعد بداية دورة النسخ (توقيت اليمن)')
                    ->options($times)->searchable()->required()->native(false)
                    ->helperText('الساعة بنظام 24 ساعة؛ يتحقق المجدول كل خمس دقائق.'),
            ])->columns(2),
            Section::make('وجهة النسخ الاحتياطي')->schema([
                Select::make('destination')->label('جهاز استقبال النسخة الإضافية')->options([
                    'msi' => 'MSI — Ubuntu (الجهاز المعتمد)',
                    'server' => 'الاستضافة فقط (لا مزامنة خارجية)',
                ])->required()->native(false)
                    ->helperText('تُحفظ النسخة المشفرة أولاً على الاستضافة. يسحب الجهاز المعتمد النسخ عند اتصاله. إضافة جهاز جديد تتطلب اعتماده أولاً.'),
            ]),
        ])->statePath('data');
    }

    public function submit(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        try {
            app(BackupControl::class)->save($data);
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title('تعذر حفظ إعدادات النسخ الاحتياطي')->send();
            return;
        }
        $this->form->fill(app(BackupControl::class)->policy());
        Notification::make()->success()->title('تم حفظ إعدادات النسخ الاحتياطي')->send();
    }

    public function backupOverview(): array
    {
        abort_unless(static::canAccess(), 403);
        $control = app(BackupControl::class);
        return ['policy' => $control->policy(), 'status' => $control->status(),
            'device' => $control->deviceStatus(), 'next' => $control->nextDue()];
    }
}
