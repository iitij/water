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
