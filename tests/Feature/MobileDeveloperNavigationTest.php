<?php

namespace Tests\Feature;

use App\Filament\Pages\MobileDeveloperSettings;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\CashSessions\CashSessionResource;
use App\Filament\Resources\CashSessions\Pages\ListCashSessions;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\Meters\MeterResource;
use App\Filament\Resources\MobileAppLogResource;
use App\Filament\Resources\MobileAppLogResource\Pages\ListMobileAppLogs;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\SubscriberResource;
use App\Filament\Resources\VoidRequests\Pages\ListVoidRequests;
use App\Filament\Resources\VoidRequests\VoidRequestResource;
use App\Models\CashSession;
use App\Models\MobileDeveloperSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileDeveloperNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_sidebar_keeps_original_resources_and_has_one_entry_for_mobile_additions(): void
    {
        $admin = $this->admin();
        foreach (['ViewAny:Subscriber', 'ViewAny:Meter', 'ViewAny:Invoice', 'ViewAny:Payment'] as $name) {
            $admin->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($admin);

        $urls = [];
        foreach (Filament::getNavigation() as $group) {
            $this->collectNavigationUrls($group->getItems(), $urls);
        }

        $this->assertContains(MobileDeveloperSettings::getUrl(), $urls);
        foreach ($this->toolResources() as $resource) {
            $this->assertNotContains($resource::getUrl(), $urls);
            $this->assertFalse($resource::shouldRegisterNavigation());
        }
        foreach ([SubscriberResource::class, MeterResource::class, InvoiceResource::class, PaymentResource::class] as $resource) {
            $this->assertContains($resource::getUrl(), $urls);
        }
    }

    public function test_admin_hub_renders_all_tools_at_their_existing_urls(): void
    {
        $this->actingAs($this->admin());
        $tools = (new MobileDeveloperSettings)->getTools();

        $this->assertSame(array_keys($this->toolResources()), array_column($tools, 'key'));
        $response = $this->get('/admin/mobile-developer-settings')->assertOk();
        foreach ($tools as $tool) {
            foreach (['key', 'label', 'description', 'icon', 'url'] as $field) {
                $this->assertArrayHasKey($field, $tool);
            }
            $this->assertSame($this->toolResources()[$tool['key']]::getUrl(), $tool['url']);
            $response->assertSee($tool['label'])->assertSee($tool['url'], false);
        }
        $response->assertSee('رمز جديد لوضع المطور');

        foreach ([ListCashSessions::class, ListVoidRequests::class, ListAuditEvents::class, ListMobileAppLogs::class] as $page) {
            $this->assertSame('إعدادات مطور الهاتف', (new $page)->getBreadcrumbs()[MobileDeveloperSettings::getUrl()]);
        }
    }

    public function test_auditor_can_use_the_hub_without_receiving_pin_management_access(): void
    {
        Gate::policy(CashSession::class, DeniedMobileCashSessionPolicy::class);
        $auditor = User::factory()->create();
        $auditor->givePermissionTo(Permission::findOrCreate('audit.view', 'web'));
        $this->actingAs($auditor);

        $this->assertTrue(MobileDeveloperSettings::canAccess());
        $this->assertFalse(MobileDeveloperSettings::canManageSettings());
        $this->assertSame(['audit_events'], array_column((new MobileDeveloperSettings)->getTools(), 'key'));
        $this->get(MobileDeveloperSettings::getUrl())
            ->assertOk()
            ->assertSee(AuditEventResource::getUrl(), false)
            ->assertDontSee('رمز جديد لوضع المطور');

        $settings = MobileDeveloperSetting::current();
        $oldHash = $settings->pin_hash;
        Livewire::test(MobileDeveloperSettings::class)
            ->set('data.pin', '99112')
            ->call('submit')
            ->assertForbidden();
        $this->assertSame($oldHash, $settings->fresh()->pin_hash);

        $this->get(AuditEventResource::getUrl())->assertOk();
        $this->get(VoidRequestResource::getUrl())->assertForbidden();
        $this->get(MobileAppLogResource::getUrl())->assertForbidden();
    }

    public function test_users_without_any_tool_access_cannot_open_the_hub(): void
    {
        Gate::policy(CashSession::class, DeniedMobileCashSessionPolicy::class);
        $this->assertFalse(MobileDeveloperSettings::canAccess());
        $this->assertSame([], (new MobileDeveloperSettings)->getTools());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(MobileDeveloperSettings::canAccess());
        $this->assertSame([], (new MobileDeveloperSettings)->getTools());
        $this->get(MobileDeveloperSettings::getUrl())->assertForbidden();
    }

    public function test_admin_can_change_the_pin_from_the_hub(): void
    {
        $this->actingAs($this->admin());
        $this->assertTrue(MobileDeveloperSettings::canManageSettings());

        Livewire::test(MobileDeveloperSettings::class)
            ->set('data.pin', '99112')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('data.pin', '');

        $this->assertTrue(Hash::check('99112', MobileDeveloperSetting::current()->pin_hash));
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $admin;
    }

    /** @return array<string, class-string> */
    private function toolResources(): array
    {
        return [
            'cash_sessions' => CashSessionResource::class,
            'void_requests' => VoidRequestResource::class,
            'audit_events' => AuditEventResource::class,
            'mobile_logs' => MobileAppLogResource::class,
        ];
    }

    /** @param iterable<NavigationItem> $items @param array<string> $urls */
    private function collectNavigationUrls(iterable $items, array &$urls): void
    {
        foreach ($items as $item) {
            $urls[] = $item->getUrl();
            $this->collectNavigationUrls($item->getChildItems(), $urls);
        }
    }
}

class DeniedMobileCashSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }
}
