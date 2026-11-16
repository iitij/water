<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\ReadingFault;
use App\Models\RouteVisit;
use App\Models\Setting;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * تغطية إصلاحات الاتصال بين التطبيق والخادم:
 * - أعطال القراءة الميدانية (رفع/مزامنة/قائمة) — كانت محلية فقط.
 * - زيارات جولة المسار — كانت محلية فقط.
 * - تقارير أعطال التطبيق إلى /system/error-reports.
 * - إصلاحات العقود: reports حدود اليوم، paid response، ملخص المستحقات،
 *   فلتر invoice_id للسندات، شريحة التعرفة q/query، رفع الشعار بـ file.
 */
class MobileConnectivityFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function meterFixture(): array
    {
        $meter = Meter::create([
            'unique_code' => 'M-'.Str::random(6),
            'subscriber_id' => Subscriber::create([
                'name' => 'مشترك اختبار',
                'phone' => '0770000001',
                'square_id' => Square::create(['name' => 'مربع س'.Str::random(4)])->id,
                'status' => 'active',
            ])->id,
            'initial_reading' => 0,
            'status' => 'active',
        ]);
        Device::create([
            'user_id' => $this->admin->id,
            'device_uuid' => 'fix-test-device',
            'name' => 'جهاز الاختبار',
            'platform' => 'android',
        ]);

        return [$meter, $meter->subscriber];
    }

    // / إصدار فاتورة استهلاك عبر الـ API نفسه (كما يفعل التطبيق) ثم تحديثها.
    private function createMeterInvoiceViaApi(Meter $meter, Subscriber $subscriber)
    {
        // قراءة حالية قبل إصدار الفاتورة حتى يوجد استهلاك.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/readings', [
                'meter_id' => $meter->id,
                'reading_date' => now()->toDateString(),
                'current_reading' => 15,
            ])
            ->assertCreated();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/invoices/generate-meter', [
                'subscriber_id' => $subscriber->id,
                'meter_id' => $meter->id,
            ])
            ->assertCreated();

        $invoice = Invoice::findOrFail($response->json('invoice.id'));
        $invoice->refresh();

        return $invoice;
    }

    // ─── أعطال القراءة ───────────────────────────────────────────────

    public function test_reading_fault_can_be_reported_and_is_idempotent(): void
    {
        [$meter] = $this->meterFixture();
        $uuid = (string) Str::uuid();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/readings/faults', [
                'meter_id' => $meter->id,
                'reading_date' => now()->toDateString(),
                'fault_type' => 'meter_locked',
                'notes' => 'باب مغلق والمشترك خارج المدينة',
                'recorded_at' => now()->toIso8601String(),
                'sync_uuid' => $uuid,
            ])
            ->assertCreated()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('fault.fault_type', 'meter_locked');

        // نفس sync_uuid مرة أخرى → يُعاد نفس السجل دون تكرار.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/readings/faults', [
                'meter_id' => $meter->id,
                'reading_date' => now()->toDateString(),
                'fault_type' => 'meter_locked',
                'sync_uuid' => $uuid,
            ])
            ->assertCreated()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, ReadingFault::count());
        $fault = ReadingFault::first();
        $this->assertSame($meter->subscriber->square_id, $fault->square_id);
        $this->assertSame($meter->subscriber->id, $fault->subscriber_id);
        $this->assertSame($this->admin->id, $fault->user_id);
    }

    public function test_reading_faults_sync_batch_and_index(): void
    {
        [$meter] = $this->meterFixture();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/readings/faults/sync-batch', [
                'faults' => [
                    [
                        'meter_id' => $meter->id,
                        'reading_date' => now()->toDateString(),
                        'fault_type' => 'subscriber_absent',
                        'sync_uuid' => (string) Str::uuid(),
                    ],
                    [
                        'meter_id' => $meter->id,
                        'reading_date' => now()->toDateString(),
                        'fault_type' => 'meter_broken',
                        'notes' => 'الزجاج مكسور',
                        'sync_uuid' => (string) Str::uuid(),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('synced_count', 2);

        // إعادة نفس الدفعة → كلها مكررة.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/readings/faults/sync-batch', [
                'faults' => [
                    [
                        'meter_id' => $meter->id,
                        'reading_date' => now()->toDateString(),
                        'fault_type' => 'subscriber_absent',
                        'sync_uuid' => ReadingFault::first()->sync_uuid,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('duplicate_count', 1);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/readings/faults?fault_type=meter_broken')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // نوع غير معروف → 422
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/readings/faults', [
                'meter_id' => $meter->id,
                'reading_date' => now()->toDateString(),
                'fault_type' => 'aliens_ate_the_meter',
                'sync_uuid' => (string) Str::uuid(),
            ])
            ->assertStatus(422);
    }

    // ─── زيارات الجولة ───────────────────────────────────────────────

    public function test_route_visits_sync_and_index(): void
    {
        [$meter] = $this->meterFixture();
        $squareId = $meter->subscriber->square_id;

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/route-visits/sync-batch', [
                'visits' => [
                    [
                        'route_date' => now()->toDateString(),
                        'meter_id' => $meter->id,
                        'meter_code' => $meter->unique_code,
                        'status' => 'visited',
                        'visit_at' => now()->toIso8601String(),
                        'sync_uuid' => (string) Str::uuid(),
                    ],
                    [
                        'route_date' => now()->toDateString(),
                        'meter_id' => $meter->id,
                        'status' => 'payment_collected',
                        'collected_amount' => 500,
                        'sync_uuid' => (string) Str::uuid(),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('synced_count', 2);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/route-visits?square_id={$squareId}&route_date=".now()->toDateString())
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // زيارة واحدة — idempotent store
        $uuid = (string) Str::uuid();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/route-visits', [
                'route_date' => now()->toDateString(),
                'meter_id' => $meter->id,
                'status' => 'no_answer',
                'sync_uuid' => $uuid,
            ])
            ->assertCreated()
            ->assertJsonPath('duplicate', false);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/route-visits', [
                'route_date' => now()->toDateString(),
                'meter_id' => $meter->id,
                'status' => 'no_answer',
                'sync_uuid' => $uuid,
            ])
            ->assertCreated()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(3, RouteVisit::count());
    }

    // ─── تقارير أعطال التطبيق ────────────────────────────────────────

    public function test_client_error_reports_are_received_and_listed(): void
    {
        // RefreshDatabase لا يعزل الملفات؛ نمنع قراءة سجلات التطوير أو مسحها.
        $testStorage = sys_get_temp_dir().'/water-client-reports-'.Str::uuid();
        File::ensureDirectoryExists($testStorage.'/logs');
        $this->app->useStoragePath($testStorage);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($testStorage));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/system/error-reports', [
                'reports' => [
                    [
                        'message' => 'Unhandled exception in readings screen',
                        'stack' => 'at Foo.bar (reading.dart:12)',
                        'context' => ['name' => 'submit_reading'],
                        'occurred_at' => now()->toIso8601String(),
                        'app_version' => '1.0.0+1',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('received_count', 1);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/system/api-logs')
            ->assertOk();

        $response->assertJsonPath('summary.client_reports_count', 1);
        $reports = $response->json('client_reports');
        $this->assertSame('Unhandled exception in readings screen', $reports[0]['message']);
        $this->assertSame($this->admin->id, $reports[0]['user_id']);

        // والمسح يزيلها أيضاً.
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/system/api-logs')
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/system/api-logs')
            ->assertOk()
            ->assertJsonPath('summary.client_reports_count', 0);
    }

    // ─── إصلاحات العقود ──────────────────────────────────────────────

    public function test_reports_include_payments_made_today_after_midnight(): void
    {
        [$meter, $subscriber] = $this->meterFixture();

        $tariff = Tariff::create(['name' => 'أساسي', 'unit_price' => 100, 'is_active' => true, 'start_date' => now()->subYear()->toDateString()]);
        $invoice = $this->createMeterInvoiceViaApi($meter, $subscriber);
        $invoice->refresh();

        // دفع الآن (وقت اليوم الحالي بعد منتصف الليل) — كان يُستبعد من التقرير.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/invoices/{$invoice->id}/pay", [
                'amount' => (float) $invoice->total_amount,
                'payment_method' => 'cash',
            ])
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports?from_date='.now()->startOfMonth()->toDateString().'&to_date='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('summary.collection_total', fn ($value) => abs((float) $value - (float) $invoice->total_amount) < 0.01);
    }

    public function test_invoice_pay_response_contains_invoice_totals(): void
    {
        [$meter, $subscriber] = $this->meterFixture();

        $invoice = $this->createMeterInvoiceViaApi($meter, $subscriber);
        $invoice->refresh();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/invoices/{$invoice->id}/pay", [
                'amount' => 300,
                'payment_method' => 'cash',
            ])
            ->assertCreated();

        $response->assertJsonStructure([
            'message',
            'payment',
            'invoice' => ['id', 'total_amount', 'amount_paid'],
            'invoice_status',
            'receipt_data' => ['message_template'],
        ]);
        $this->assertSame(300.0, (float) $response->json('invoice.amount_paid'));
    }

    public function test_unpaid_invoices_summary_returns_app_contract_keys(): void
    {
        [$meter, $subscriber] = $this->meterFixture();

        $invoice = $this->createMeterInvoiceViaApi($meter, $subscriber);
        $invoice->refresh();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/subscribers/{$subscriber->id}/unpaid-invoices-summary")
            ->assertOk()
            ->assertJsonStructure([
                'subscriber_id',
                'general_balance',
                'invoices',
                'total_remaining',
                // مفاتيح عقد تطبيق الهاتف:
                'invoice_id',
                'bill_number',
                'due_amount',
                'outstanding_balance',
                'credit_balance',
                'open_invoices_count',
            ])
            ->assertJsonPath('invoice_id', $invoice->id)
            ->assertJsonPath('open_invoices_count', 1)
            ->assertJsonMissingPath('uninvoiced_subscription_fee');
    }

    public function test_payments_index_filters_by_invoice_id(): void
    {
        [$meter, $subscriber] = $this->meterFixture();

        $invoice = $this->createMeterInvoiceViaApi($meter, $subscriber);
        $invoice->refresh();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/invoices/{$invoice->id}/pay", [
                'amount' => 200,
                'payment_method' => 'cash',
            ])
            ->assertCreated();

        // السندات المرتبطة بالفاتورة فقط (يرسلها التطبيق من تفاصيل الفاتورة).
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/payments?invoice_id='.$invoice->id.'&voided=all')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // وفلتر voided=only يعيد لا شيء لعدم وجود سندات ملغاة.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/payments?invoice_id='.$invoice->id.'&voided=only')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_payment_model_appends_voided_flags(): void
    {
        [$meter, $subscriber] = $this->meterFixture();

        $invoice = $this->createMeterInvoiceViaApi($meter, $subscriber);
        $invoice->refresh();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/invoices/{$invoice->id}/pay", ['amount' => 100, 'payment_method' => 'cash'])
            ->assertCreated();

        $payment = Payment::latest('id')->first();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('payment.voided', false)
            ->assertJsonPath('payment.void_requested', false);
    }

    public function test_tariffs_index_returns_top_level_paginator_and_accepts_query_alias(): void
    {
        Tariff::create(['name' => 'شريحة النهار', 'unit_price' => 150, 'is_active' => true, 'start_date' => now()->toDateString()]);

        // البنية الجديدة: data = قائمة مباشرة (يقرأها unwrapList في التطبيق).
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/tariffs')
            ->assertOk();

        $this->assertIsList($response->json('data'));
        $this->assertNotNull($response->json('active_price'));
        $this->assertNotNull($response->json('current_page'));
        $this->assertNotNull($response->json('total'));

        // البحث بـ query (الاسم القديم الذي يرسله التطبيق) وبـ q.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/tariffs?query='.urlencode('نهار'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/tariffs?q='.urlencode('نهار'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_settings_logo_upload_accepts_file_field(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/settings/upload-logo', [
                'key' => 'invoice_logo',
                'file' => UploadedFile::fake()->image('logo.png', 64, 64),
            ])
            ->assertOk()
            ->assertJsonStructure(['message', 'key', 'path', 'url']);

        $this->assertNotNull(Setting::get('invoice_logo'));
    }

    public function test_whatsapp_local_fallback_routes_exist(): void
    {
        // المسارات الاحتياطية التي يرسلها التطبيق عند فشل المسارات الأساسية.
        // status يعيد 200 دائماً (offline)، بينما logout/send-test يعيدان 503
        // عندما يكون جسر الواتساب المحلي غير مشغّل — المهم ألا تكون 404/405.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/whatsapp/local-status')
            ->assertOk()
            ->assertJsonStructure(['status', 'connected']);

        $logout = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/whatsapp/local-logout', []);
        // 200 عند عمل الجسر المحلي، أو 503 عندما لا يعمل — المهم ألا تكون 404/405.
        $this->assertContains($logout->status(), [200, 503]);

        $sendTest = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/whatsapp/local-send-test', ['phone' => '0770000000', 'message' => 'اختبار']);
        $this->assertContains($sendTest->status(), [200, 503]);
    }

    public function test_message_template_is_rendered_in_payment_receipt(): void
    {
        [$meter, $subscriber] = $this->meterFixture();

        $invoice = $this->createMeterInvoiceViaApi($meter, $subscriber);
        $invoice->refresh();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/invoices/{$invoice->id}/pay", ['amount' => 100, 'payment_method' => 'cash'])
            ->assertCreated();

        $template = $response->json('receipt_data.message_template');
        $this->assertIsString($template);
        // نص مُصيّر: لا يبقى أي placeholder غير مستبدل من قالب الفاتورة.
        $this->assertStringNotContainsString('{subscriber_name}', $template);
        $this->assertStringContainsString($invoice->bill_number, $template);
    }
}
