<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * اختبارات أساس الإصدار 2.0: صندوق المحصّل، إلغاء السند، تدوير التوكن،
 * وإبطال الأجهزة. مرجع: docs/V2_MOBILE_PLAN_AR.md — الدورتان S1 وS2.
 */
class CashSessionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Square $square;

    private Subscriber $subscriber;

    private Meter $meter;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'collector', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('super_admin');

        Tariff::create([
            'name' => 'الشريحة الأساسية',
            'unit_price' => 300,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
        ]);

        $this->square = Square::create(['name' => 'المربع الأول']);
        $this->subscriber = Subscriber::create([
            'name' => 'مشترك صندوق',
            'square_id' => $this->square->id,
            'status' => 'active',
            'phone' => '777000111',
        ]);
        $this->meter = Meter::create([
            'unique_code' => 'CS-MTR-1',
            'path_number' => 3,
            'subscriber_id' => $this->subscriber->id,
            'initial_reading' => 0,
        ]);
        $this->invoice = Invoice::create([
            'subscriber_id' => $this->subscriber->id,
            'meter_id' => $this->meter->id,
            'bill_number' => 'INV-CS-1',
            'bill_date' => now()->toDateString(),
            'consumption_fee' => 1000,
            'total_amount' => 1000,
            'amount_paid' => 0,
            'status' => 'issued',
        ]);
    }

    public function test_cash_session_lifecycle_with_payments_and_variance(): void
    {
        $open = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/cash-sessions', ['opening_amount' => 0])
            ->assertCreated()
            ->assertJsonPath('cash_session.state', 'open');

        $sessionId = $open->json('cash_session.id');

        // لا يُسمح بصندوقين مفتوحين لنفس المستخدم.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/cash-sessions', [])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/invoices/{$this->invoice->id}/pay", [
                'amount' => 1000,
                'payment_method' => 'cash',
                'sync_uuid' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();
        $this->assertSame($sessionId, $payment->cash_session_id);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/cash-sessions/current')
            ->assertOk()
            ->assertJsonPath('cash_session.expected_amount', '1000.00')
            ->assertJsonPath('cash_session.receipts', 1);

        // فرق 50 ر.ي ناقص.
        $closed = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/cash-sessions/{$sessionId}/close", ['counted_amount' => 950])
            ->assertOk()
            ->assertJsonPath('cash_session.state', 'closed')
            ->assertJsonPath('cash_session.variance_amount', '-50.00');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/cash-sessions/{$sessionId}/approve", [
                'approve' => true,
                'notes' => 'فرق مبرر ومُعتمد.',
            ])
            ->assertOk()
            ->assertJsonPath('cash_session.state', 'approved');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/cash-sessions/{$sessionId}/report")
            ->assertOk()
            ->assertJsonPath('payments.0.voided', false)
            ->assertJsonStructure(['cash_session', 'payments', 'generated_at']);
    }

    public function test_voiding_a_payment_restores_the_invoice_and_leaves_an_audit_trail(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/invoices/'.$this->invoice->id.'/pay', [
                'amount' => 600,
                'payment_method' => 'cash',
                'sync_uuid' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();
        $this->assertEquals('partially_paid', $this->invoice->fresh()->status);

        // السبب القصير مرفوض.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/payments/{$payment->id}/void", ['reason' => 'خطأ'])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/payments/{$payment->id}/void", [
                'reason' => 'خطأ في إدخال المبلغ وتم تصحيحه مع المشترك',
            ])
            ->assertOk()
            ->assertJsonPath('payment.voided', true)
            ->assertJsonPath('receipt_data.voided', true);

        $payment->refresh();
        $this->assertNotNull($payment->voided_at);
        $this->assertSame($this->admin->id, $payment->voided_by);

        $this->invoice->refresh();
        $this->assertEquals(0, (float) $this->invoice->amount_paid);
        $this->assertSame('issued', $this->invoice->status);

        // السند لم يُحذف، لكنه لا يظهر في الاستعلام النشط.
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/payments')
            ->assertOk()
            ->assertJsonMissing(['id' => $payment->id]);

        $this->assertDatabaseHas('audit_events', [
            'event' => 'payment.voided',
            'entity_id' => (string) $payment->id,
        ]);
    }

    public function test_refresh_rotates_the_token_and_revokes_the_old_one(): void
    {
        $login = $this->postJson('/api/login', [
            'email' => $this->admin->email,
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['access_token', 'expires_at']);

        $oldToken = $login->json('access_token');

        $refreshed = $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->postJson('/api/refresh')
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'user']);

        $newToken = $refreshed->json('access_token');
        $this->assertNotSame($oldToken, $newToken);

        // داخل اختبارات Laravel يتشارك كل الطلبات نسخة التطبيق نفسها، وحارس
        // RequestGuard يخزّن المستخدم بعد أول حل، لذا نُصفّي الحواجز المحفوظة
        // حتى يُعاد التحقق من التوكن فعلياً في الطلبات التالية (في الإنتاج كل
        // طلب يعمل بنسخة تطبيق مستقلة والسلوك صحيح بدون هذا السطر).
        $this->app->make(AuthManager::class)->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$newToken)
            ->getJson('/api/me')
            ->assertOk();

        $this->app->make(AuthManager::class)->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_a_revoked_device_cannot_call_the_api(): void
    {
        $device = Device::create([
            'user_id' => $this->admin->id,
            'device_uuid' => 'device-for-test',
            'name' => 'هاتف اختبار',
            'platform' => 'android',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Device-Id', 'device-for-test')
            ->getJson('/api/me')
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/devices/{$device->id}")
            ->assertOk();

        $this->assertNotNull($device->fresh()->revoked_at);

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Device-Id', 'device-for-test')
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_bulk_tariff_restore_and_force_delete(): void
    {
        $a = Tariff::create(['name' => 'أ', 'unit_price' => 100, 'is_active' => true, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
        $b = Tariff::create(['name' => 'ب', 'unit_price' => 200, 'is_active' => true, 'start_date' => '2026-02-01']);
        $a->delete();
        $b->delete();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/tariffs/bulk-restore', ['ids' => [$a->id]])
            ->assertOk()
            ->assertJsonPath('restored_count', 1);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/tariffs/bulk-force-delete', ['ids' => [$b->id]])
            ->assertOk()
            ->assertJsonPath('force_deleted_count', 1);

        $this->assertDatabaseMissing('tariffs', ['id' => $b->id]);
        $this->assertDatabaseHas('tariffs', ['id' => $a->id, 'deleted_at' => null]);
    }

    public function test_daily_cash_session_pdf_report_is_rendered_from_server_data(): void
    {
        Pdf::fake();

        $sessionId = $this->openSessionWithOnePayment();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/cash-sessions/{$sessionId}/close", [
                'counted_amount' => 1000,
                'notes' => 'العدّ مطابق للسندات.',
            ])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->get("/api/cash-sessions/{$sessionId}/report/pdf")
            ->assertOk();

        Pdf::assertRespondedWithPdf(function (PdfBuilder $pdf) {
            return $pdf->isDownload()
                && $pdf->viewName === 'pdf.cash_session'
                && str_ends_with($pdf->downloadName, '.pdf')
                && $pdf->contains(['تقرير صندوق المحصّل', 'نقداً', 'الفرق']);
        });
    }

    public function test_pdf_report_contains_receipts_and_counted_amount_in_words(): void
    {
        Pdf::fake();

        $sessionId = $this->openSessionWithOnePayment();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/cash-sessions/{$sessionId}/close", ['counted_amount' => 1000])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->get("/api/cash-sessions/{$sessionId}/report/pdf")
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn (PdfBuilder $pdf) => $pdf->contains([
                'سندات الصندوق',
                'النقد المعدود كتابةً',
                '1000.00',
            ]),
        );
    }

    public function test_a_collector_cannot_read_another_collectors_pdf_report(): void
    {
        Pdf::fake();

        $sessionId = $this->openSessionWithOnePayment();

        $other = User::factory()->create(['role' => 'collector']);
        $other->assignRole('collector');

        $this->actingAs($other, 'sanctum')
            ->get("/api/cash-sessions/{$sessionId}/report/pdf")
            ->assertForbidden();
    }

    /** يفتح صندوقاً للمدير ويسجّل سنداً واحداً عليه، ويعيد رقم الصندوق. */
    private function openSessionWithOnePayment(): int
    {
        $open = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/cash-sessions', ['opening_amount' => 0])
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/invoices/{$this->invoice->id}/pay", [
                'amount' => 1000,
                'payment_method' => 'cash',
                'sync_uuid' => (string) Str::uuid(),
            ])
            ->assertCreated();

        return (int) $open->json('cash_session.id');
    }
}
