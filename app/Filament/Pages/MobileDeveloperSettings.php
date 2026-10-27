<?php

namespace App\Filament\Pages;

use App\Models\MobileDeveloperSetting;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class MobileDeveloperSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-wrench-screwdriver';
    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';
    protected static ?string $navigationLabel = 'إعدادات مطور الهاتف';
    protected static ?string $title = 'إعدادات مطور الهاتف';
    protected static ?int $navigationSort = 12;
    protected string $view = 'filament.pages.reading-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() === true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $settings = MobileDeveloperSetting::current();
        $this->form->fill([
            'pin' => '',
            'show_whatsapp_settings' => $settings->show_whatsapp_settings,
            'allow_log_upload' => $settings->allow_log_upload,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([
            TextInput::make('pin')
                ->label('PIN جديد لوضع المطور')
                ->password()
                ->revealable()
                ->rule('regex:/^[0-9]{5,12}$/')
                ->helperText('اتركه فارغاً للإبقاء على الرمز الحالي. تغيير الرمز يلغي جلسات المطور المفتوحة.'),
            Toggle::make('show_whatsapp_settings')
                ->label('إظهار إعدادات واتساب داخل وضع المطور'),
            Toggle::make('allow_log_upload')
                ->label('السماح برفع سجلات تطبيق الهاتف')
                ->helperText('يرفع التطبيق السجلات فقط عند طلب المطور من الجهاز.'),
        ])->statePath('data');
    }

    public function submit(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $settings = MobileDeveloperSetting::current();
        if (! empty($data['pin'])) {
            $settings->pin_hash = Hash::make($data['pin']);
        }
        $settings->show_whatsapp_settings = (bool) ($data['show_whatsapp_settings'] ?? false);
        $settings->allow_log_upload = (bool) ($data['allow_log_upload'] ?? false);
        $settings->save();
        $this->form->fill(['pin' => '',
            'show_whatsapp_settings' => $settings->show_whatsapp_settings,
            'allow_log_upload' => $settings->allow_log_upload]);
        Notification::make()->success()->title('تم حفظ إعدادات مطور الهاتف')->send();
    }
}
