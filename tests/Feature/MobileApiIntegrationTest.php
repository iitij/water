<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileApiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('super_admin');
    }

    public function test_auth_login_me_profile_and_logout(): void
    {
        $login = $this->postJson('/api/login', [
            'email' => $this->admin->email,
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['access_token', 'token_type', 'user']);

        $token = $login->json('access_token');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', $this->admin->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/profile', [
                'name' => 'مدير محدث',
                'email' => $this->admin->email,
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'مدير محدث');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout')
            ->assertOk();
    }

    public function test_meters_readings_sync_uuid_invoices_and_payments_flow(): void
    {
        Tariff::create([
            'name' => 'الشريحة الأساسية',
            'unit_price' => 300,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
        ]);

        $square = Square::create(['name' => 'المربع الأول']);
        $subscriber = Subscriber::create([
            'name' => 'مشترك تجريبي',
            'subscription_start_date' => '2026-01-01',
            'square_id' => $square->id,
            'status' => 'active',
            'phone' => '777123456',
        ]);

        // Create meter via API
        $meterResp = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/meters', [
                'unique_code' => 'MTR-100',
                'path_number' => 15,
                'subscriber_id' => $subscriber->id,
                'initial_reading' => 10,
            ])
            ->assertCreated();

        $meterId = $meterResp->json('meter.id');

        // Validate path
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/meters/validate-path', [
                'path_number' => 15,
                'square_id' => $square->id,
            ])
            ->assertOk()
            ->assertJsonPath('available', false);

        // Meter period calculation
        $periodResp = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/meters/{$meterId}/period")
            ->assertOk();

        // Store reading with sync_uuid and verify idempotency
        $readingUuid = (string) Str::uuid();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/readings', [
                'meter_id' => $meterId,
                'reading_date' => now()->toDateString(),
                'current_reading' => 25,
                'period_start' => $periodResp->json('period_start'),
                'period_end' => $periodResp->json('period_end'),
                'sync_uuid' => $readingUuid,
            ])
            ->assertCreated()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('reading.consumption_units', 15);

        // Duplicate sync_uuid returns 200 with duplicate=true
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/readings', [
                'meter_id' => $meterId,
                'reading_date' => now()->toDateString(),
                'current_reading' => 25,
                'sync_uuid' => $readingUuid,
            ])
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        // Generate meter invoice
        $invoiceResp = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/invoices/generate-meter', [
                'subscriber_id' => $subscriber->id,
                'meter_id' => $meterId,
            ])
            ->assertCreated();

        $invoiceId = $invoiceResp->json('invoice.id');
        $this->assertEquals(4500.0, (float) $invoiceResp->json('invoice.total_amount'));

        // Pay invoice with sync_uuid
        $paymentUuid = (string) Str::uuid();
        $payResp = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/invoices/{$invoiceId}/pay", [
                'amount' => 4500,
                'payment_method' => 'cash',
                'sync_uuid' => $paymentUuid,
            ])
            ->assertCreated()
            ->assertJsonPath('invoice_status', 'paid');

        $paymentId = $payResp->json('payment.id');
        $this->assertNotNull($paymentId);

        // WhatsApp links for invoice and payment
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/invoices/{$invoiceId}/whatsapp-link")
            ->assertOk()
            ->assertJsonStructure(['url', 'message']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/payments/{$paymentId}/whatsapp-link")
            ->assertOk()
            ->assertJsonStructure(['url', 'message']);

        // Dashboard & reports
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure(['stats_overview', 'monthly_revenue_chart', 'latest_invoices', 'overdue_invoices']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('summary.collection_total', 4500);
    }

    public function test_tariffs_settings_roles_users_and_exports(): void
    {
        // Tariffs CRUD + soft delete + restore
        $tariffResp = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/tariffs', [
                'name' => 'تعرفة جديدة',
                'unit_price' => 350,
                'is_active' => true,
            ])
            ->assertCreated();

        $tariffId = $tariffResp->json('tariff.id');

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/tariffs/{$tariffId}")
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/tariffs/{$tariffId}/restore")
            ->assertOk();

        // Settings
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/settings')
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/settings/reading', [
                'reading_period' => 'monthly',
                'reading_day' => 'last_day',
                'grace_period' => 14,
            ])
            ->assertOk();

        // Roles & Permissions matrix
        Permission::firstOrCreate(['name' => 'ViewAny:Invoice', 'guard_name' => 'web']);
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/roles/permissions-matrix')
            ->assertOk()
            ->assertJsonStructure(['groups', 'permissions', 'roles']);

        // Users index
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/users')
            ->assertOk();

        // Exports summary
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/exports/summary')
            ->assertOk()
            ->assertJsonStructure(['totalSubscribers', 'totalInvoices', 'totalReadings', 'totalSquares']);

        // WhatsApp server status
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/whatsapp-server/status')
            ->assertOk()
            ->assertJsonStructure(['status', 'connected', 'qr']);
    }
}
