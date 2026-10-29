<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Resources\CashSessions\CashSessionResource;
use App\Filament\Resources\MobileAppLogResource;
use App\Filament\Resources\VoidRequests\VoidRequestResource;
use App\Models\MobileDeveloperSetting;
use App\Support\MobileLaravelChangeInventory;
use Filament\Forms\Components\TextInput;
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
    protected string $view = 'filament.pages.mobile-developer-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (static::canManageSettings()) {
            return true;
        }

        if (! auth()->check()) {
            return false;
        }

        foreach (static::toolDefinitions() as $tool) {
            if ($tool['resource']::canAccess()) {
                return true;
            }
        }

        return false;
    }

    public static function canManageSettings(): bool
    {
        return auth()->user()?->isAdmin() === true;
    }

    public function getChangeInventory(): ?array
    {
        return static::canManageSettings() ? MobileLaravelChangeInventory::snapshot() : null;
    }

    public static function getNavigationItemActiveRoutePattern(): string | array
    {
        return [
            static::getRouteName(),
            ...array_map(
                fn (array $tool): string => $tool['resource']::getRouteBaseName().'.*',
                static::toolDefinitions(),
            ),
        ];
    }

    /** The hub uses each resource's existing authorization, including direct links. */
    public function getTools(): array
    {
        if (! auth()->check()) {
            return [];
        }

        $tools = [];
        foreach (static::toolDefinitions() as $tool) {
            $resource = $tool['resource'];
            if (! $resource::canAccess()) {
                continue;
            }

            unset($tool['resource']);
            $tools[] = [
                ...$tool,
                'url' => $resource::getUrl('index'),
                'badge' => $resource::getNavigationBadge(),
            ];
        }

        return $tools;
    }

    private static function toolDefinitions(): array
    {
        return [
            [
                'key' => 'cash_sessions',
                'resource' => CashSessionResource::class,
                'label' => 'صناديق المحصّلين',
                'description' => 'مراجعة الصناديق والفروقات النقدية واعتماد الصناديق المغلقة.',
                'icon' => 'heroicon-o-banknotes',
            ],
            [
                'key' => 'void_requests',
                'resource' => VoidRequestResource::class,
                'label' => 'اعتماد إلغاء السندات',
                'description' => 'مراجعة طلبات إلغاء السندات الكبيرة واتخاذ القرار عليها.',
                'icon' => 'heroicon-o-shield-exclamation',
            ],
            [
                'key' => 'audit_events',
                'resource' => AuditEventResource::class,
                'label' => 'سجل التدقيق',
                'description' => 'عرض العمليات المسجلة والمستخدم والجهاز وتفاصيل التغيير.',
                'icon' => 'heroicon-o-clipboard-document-list',
            ],
            [
                'key' => 'mobile_logs',
                'resource' => MobileAppLogResource::class,
                'label' => 'سجلات تطبيق الهاتف',
                'description' => 'عرض سجلات التشخيص المرسلة من وضع المطور في التطبيق.',
                'icon' => 'heroicon-o-document-text',
            ],
        ];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        if (static::canManageSettings()) {
            $this->form->fill(['pin' => '']);
        }
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([
            TextInput::make('pin')
                ->label('رمز جديد لوضع المطور')
                ->password()
                ->revealable()
                ->rule('regex:/^[0-9]{5,12}$/')
                ->helperText('اتركه فارغاً للإبقاء على الرمز الحالي. تغيير الرمز يلغي جلسات المطور المفتوحة.'),
        ])->statePath('data');
    }

    public function submit(): void
    {
        abort_unless(static::canManageSettings(), 403);
        $data = $this->form->getState();
        $settings = MobileDeveloperSetting::current();
        if (! empty($data['pin'])) {
            $settings->pin_hash = Hash::make($data['pin']);
        }
        $settings->save();
        $this->form->fill(['pin' => '']);
        Notification::make()->success()->title('تم حفظ إعدادات مطور الهاتف')->send();
    }
}
