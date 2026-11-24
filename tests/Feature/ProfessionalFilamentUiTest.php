<?php

namespace Tests\Feature;

use App\Filament\Pages\EditProfilePage;
use App\Filament\Pages\ExportDataPage;
use App\Filament\Pages\InvoiceSettings;
use App\Filament\Pages\MobileDeveloperSettings;
use App\Filament\Pages\ReadingSettings;
use App\Filament\Pages\ReceiptSettings;
use App\Filament\Pages\WhatsAppSettings;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfessionalFilamentUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        Gate::before(fn (User $user) => $user->hasRole('super_admin') ? true : null);
    }

    public function test_all_custom_admin_pages_render_with_native_filament_shell(): void
    {
        foreach ([
            EditProfilePage::class,
            ExportDataPage::class,
            InvoiceSettings::class,
            ReceiptSettings::class,
            MobileDeveloperSettings::class,
            ReadingSettings::class,
            WhatsAppSettings::class,
        ] as $page) {
            $this->get($page::getUrl())->assertOk()->assertSee('fi-page', false);
        }

        $this->get(ExportDataPage::getUrl())
            ->assertOk()
            ->assertSee('قائمة المشتركين')
            ->assertSee('فواتير المشتركين')
            ->assertSee('قراءات العدادات')
            ->assertSee('fi-section', false)
            ->assertDontSee('export-card', false)
            ->assertDontSee('export-spinner', false)
            ->assertDontSee('export-hero', false);

        $this->get('/admin')->assertOk()->assertDontSee('🎉');
    }

    public function test_native_export_actions_download_all_three_workbooks(): void
    {
        $square = Square::create(['name' => 'مربع اختبار التصدير']);
        Subscriber::create(['name' => 'مشترك اختبار التصدير', 'status' => 'active', 'square_id' => $square->id]);

        $date = now()->format('Y-m-d');
        $page = Livewire::test(ExportDataPage::class)
            ->set('subscribers_square_id', (string) $square->id)
            ->set('subscribers_status', 'active')
            ->assertHasNoErrors();
        $page->callAction(TestAction::make('downloadSubscribers')->schemaComponent(true, 'form'))
            ->assertHasNoErrors()
            ->assertFileDownloaded('المشتركون_'.$date.'.xlsx');

        Livewire::test(ExportDataPage::class)
            ->set('invoices_square_id', (string) $square->id)
            ->set('invoices_from_date', '2026-11-01')
            ->set('invoices_to_date', '2026-11-30')
            ->set('invoices_status', 'issued')
            ->callAction(TestAction::make('downloadInvoices')->schemaComponent(true, 'form'))
            ->assertHasNoErrors()
            ->assertFileDownloaded('فواتير_المشتركين_'.$date.'.xlsx');

        Livewire::test(ExportDataPage::class)
            ->set('readings_square_id', (string) $square->id)
            ->set('readings_from_date', '2026-11-01')
            ->set('readings_to_date', '2026-11-30')
            ->set('readings_is_invoiced', '0')
            ->callAction(TestAction::make('downloadReadings')->schemaComponent(true, 'form'))
            ->assertHasNoErrors()
            ->assertFileDownloaded('قراءات_العدادات_'.$date.'.xlsx');
    }

    public function test_all_registered_resource_pages_render_native_filament_layouts(): void
    {
        $square = Square::create(['name' => 'مربع فحص الواجهات']);
        $subscriber = Subscriber::create(['name' => 'مشترك فحص الواجهات', 'status' => 'active', 'square_id' => $square->id]);
        $meter = \App\Models\Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'UI-TEST-METER']);
        $reading = \App\Models\Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-11-03',
            'previous_reading' => 0, 'current_reading' => 12,
            'period_start' => '2026-11-01', 'period_end' => '2026-11-03',
        ]);
        $invoice = \App\Models\Invoice::create([
            'subscriber_id' => $subscriber->id, 'meter_id' => $meter->id,
            'bill_number' => 'INV-UI-001', 'bill_date' => '2026-11-03',
            'total_amount' => 1200, 'status' => 'issued',
        ]);
        $payment = \App\Models\Payment::create([
            'subscriber_id' => $subscriber->id, 'invoice_id' => $invoice->id,
            'receipt_number' => 'REC-UI-001', 'amount' => 100,
            'payment_date' => '2026-11-03', 'payment_method' => 'cash',
        ]);
        $tariff = \App\Models\Tariff::create(['name' => 'تعرفة اختبار', 'unit_price' => 100, 'is_active' => true]);
        $role = Role::findOrCreate('ui-test-role', 'web');
        $records = collect([$square, $subscriber, $meter, $reading, $invoice, $payment, $tariff, $role, User::factory()->create()])
            ->keyBy(fn ($record) => $record::class);
        $resources = Filament::getPanel('admin')->getResources();
        $tested = 0;
        foreach ($resources as $resource) {
            foreach ($resource::getPages() as $name => $registration) {
                $parameters = [];
                if (in_array($name, ['edit', 'view'], true)) {
                    $record = $records->get($resource::getModel());
                    $this->assertNotNull($record, "Missing fixture for {$resource} {$name}");
                    $parameters['record'] = $record;
                }
                $url = $resource::getUrl($name, $parameters);
                $this->get($url)->assertOk()->assertSee('fi-page', false);
                $tested++;
                fwrite(STDOUT, "UI_RESOURCE_PAGE_OK {$resource} {$name}\n");
            }
        }
        $this->assertGreaterThanOrEqual(25, $tested);
        fwrite(STDOUT, "UI_RESOURCE_PAGES_VERIFIED: {$tested}\n");
    }

    public function test_whatsapp_local_server_section_renders_native_status_controls(): void
    {
        $page = Livewire::test(WhatsAppSettings::class)
            ->set('data.whatsapp_enabled', true)
            ->set('data.whatsapp_method', 'local_api')
            ->set('localServerStatus', 'offline')
            ->assertSee('غير متصل')
            ->assertSee('تشغيل الخادم')
            ->set('localServerStatus', 'waiting')
            ->assertSee('جاري تجهيز الخادم')
            ->set('localServerStatus', 'qr')
            ->assertSee('بانتظار الربط')
            ->set('localServerStatus', 'connected')
            ->assertSee('إيقاف الخادم')
            ->assertHasNoErrors();
    }

    public function test_custom_admin_blades_do_not_reintroduce_manual_theme_styles_or_raw_controls(): void
    {
        $files = array_merge(
            glob(resource_path('views/filament/pages/*.blade.php')),
            glob(resource_path('views/filament/widgets/*.blade.php')),
            glob(resource_path('views/filament/settings/*.blade.php')),
        );

        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/<style\b|:not\(\.dark\)|<button\b|<select\b|<input\b|<textarea\b/i', $source, $file);
            $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}]/u', $source, $file);
        }
    }
}
