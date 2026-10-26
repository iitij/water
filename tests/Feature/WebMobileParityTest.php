<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CashSession;
use App\Models\Setting;
use App\Models\User;
use App\Services\CashSessionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WebMobileParityTest extends TestCase
{
    use RefreshDatabase;

    private function auditor(string $permission): User
    {
        $user = User::factory()->create(['role' => 'reader']);
        $user->givePermissionTo(Permission::firstOrCreate([
            'name' => $permission, 'guard_name' => 'web',
        ]));

        return $user;
    }

    private function cashRecord(User $collector, string $state = 'closed'): CashSession
    {
        return CashSession::create([
            'user_id' => $collector->id,
            'session_date' => '2026-10-26',
            'state' => $state,
            'opening_amount' => 100,
            'expected_amount' => 100,
            'counted_amount' => 95,
            'variance_amount' => -5,
            'opened_at' => now()->subHour(),
            'closed_at' => $state === 'open' ? null : now(),
        ]);
    }

    public function test_web_audit_permission_can_read_the_same_details_and_filters_on_mobile(): void
    {
        $auditor = $this->auditor('audit.view');
        $actor = User::factory()->create(['name' => 'موظف التدقيق']);
        $event = AuditEvent::create([
            'event' => 'cash_session.closed',
            'actor_user_id' => $actor->id,
            'entity_type' => 'CashSession',
            'entity_id' => '21',
            'device_id' => 'field-device-1',
            'ip_address' => '192.0.2.12',
            'source' => 'mobile',
            'occurred_at' => now()->subMinutes(8),
            'before' => ['state' => 'open'],
            'after' => ['state' => 'closed'],
        ]);
        AuditEvent::create(['event' => 'payment.voided', 'source' => 'web', 'occurred_at' => now()]);

        $this->actingAs($auditor, 'sanctum')
            ->getJson('/api/audit-events?'.http_build_query([
                'source' => 'mobile', 'event' => 'cash_session.closed',
                'actor_user_id' => $actor->id, 'q' => 'موظف',
                'entity_type' => 'CashSession', 'entity_id' => '21', 'per_page' => 1,
            ]))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.actor', $actor->name)
            ->assertJsonPath('data.0.ip_address', '192.0.2.12')
            ->assertJsonPath('data.0.device_id', 'field-device-1')
            ->assertJsonPath('data.0.before.state', 'open')
            ->assertJsonPath('data.0.after.state', 'closed')
            ->assertJsonStructure(['data' => [['occurred_at', 'created_at', 'actor_user_id']]]);
    }

    public function test_audit_log_rejects_unauthorized_users_and_bad_filters(): void
    {
        $reader = User::factory()->create(['role' => 'reader']);
        $this->actingAs($reader, 'sanctum')->getJson('/api/audit-events')->assertForbidden();
        $this->actingAs($this->auditor('audit.view'), 'sanctum')
            ->getJson('/api/audit-events?source=unknown&from_date=bad&actor_user_id=-1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source', 'from_date', 'actor_user_id']);
    }

    public function test_audit_pagination_keeps_filters_and_reaches_later_events(): void
    {
        $auditor = $this->auditor('audit.view');
        foreach (range(1, 3) as $index) {
            AuditEvent::create(['event' => 'reading.saved', 'source' => 'mobile', 'occurred_at' => now()]);
        }
        $this->actingAs($auditor, 'sanctum')
            ->getJson('/api/audit-events?source=mobile&per_page=1&page=2')
            ->assertOk()->assertJsonPath('current_page', 2)->assertJsonPath('total', 3)
            ->assertJsonCount(1, 'data');
    }

    public function test_view_only_cash_review_cannot_approve_or_see_another_users_private_report_without_access(): void
    {
        $viewer = $this->auditor('ViewAny:CashSession');
        $collector = User::factory()->create(['role' => 'collector']);
        $session = $this->cashRecord($collector);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/cash-sessions?state=closed')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $session->id);
        $this->postJson("/api/cash-sessions/{$session->id}/approve", ['approve' => true])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'reader']), 'sanctum')
            ->getJson("/api/cash-sessions/{$session->id}/report")->assertForbidden();
    }

    public function test_cash_review_filters_and_report_use_server_amounts(): void
    {
        $approver = $this->auditor('cash_sessions.approve');
        $collector = User::factory()->create(['role' => 'collector']);
        $session = $this->cashRecord($collector);
        $this->cashRecord(User::factory()->create(), 'open');
        $this->actingAs($approver, 'sanctum')->getJson('/api/cash-sessions?'.http_build_query([
            'state' => 'closed', 'user_id' => $collector->id,
            'from_date' => '2026-10-26', 'to_date' => '2026-10-26',
        ]))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $session->id);
        $this->getJson("/api/cash-sessions/{$session->id}/report")
            ->assertOk()->assertJsonPath('cash_session.counted_amount', '95.00')
            ->assertJsonPath('cash_session.variance_amount', '-5.00');
    }

    public function test_cash_rejection_requires_a_meaningful_reason_and_is_final(): void
    {
        $approver = $this->auditor('cash_sessions.approve');
        $session = $this->cashRecord(User::factory()->create(['role' => 'collector']));
        $this->actingAs($approver, 'sanctum');
        foreach ([null, '', 'قصير', '          '] as $notes) {
            $this->postJson("/api/cash-sessions/{$session->id}/approve", [
                'approve' => false, 'notes' => $notes,
            ])->assertUnprocessable()->assertJsonValidationErrors('notes');
        }
        $reason = 'إعادة مراجعة المستندات لتوضيح الفرق النقدي';
        $this->postJson("/api/cash-sessions/{$session->id}/approve", [
            'approve' => false, 'notes' => $reason,
        ])->assertOk()->assertJsonPath('cash_session.state', 'rejected');
        $this->assertSame($reason, $session->fresh()->approval_notes);
        $this->postJson("/api/cash-sessions/{$session->id}/approve", [
            'approve' => true,
        ])->assertStatus(409);
        $this->assertSame('rejected', $session->fresh()->state);
    }

    public function test_closed_cash_session_can_be_approved_only_once(): void
    {
        $approver = $this->auditor('Approve:CashSession');
        $session = $this->cashRecord(User::factory()->create());
        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/cash-sessions/{$session->id}/approve", ['approve' => true])
            ->assertOk()->assertJsonPath('cash_session.state', 'approved');
        $this->postJson("/api/cash-sessions/{$session->id}/approve", [
            'approve' => false, 'notes' => 'محاولة تغيير قرار سبق اعتماده',
        ])->assertStatus(409);
        $this->assertSame('approved', $session->fresh()->state);
    }

    public function test_web_service_also_rejects_stale_decisions(): void
    {
        $approver = $this->auditor('Approve:CashSession');
        $session = $this->cashRecord(User::factory()->create());
        $staleCopy = $session->replicate();
        $staleCopy->id = $session->id;
        $staleCopy->exists = true;
        $service = app(CashSessionService::class);
        $service->decide($session, true, $approver);
        try {
            $service->decide($staleCopy, false, $approver, 'قرار من نسخة قديمة للصفحة');
            $this->fail('A stale web form must not overwrite an approved decision.');
        } catch (ValidationException $exception) {
            $this->assertSame('approved', $session->fresh()->state);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('approved', $session->fresh()->state);
        }
    }

    public function test_subscription_invoice_field_settings_are_actually_persisted_and_returned(): void
    {
        $editor = $this->auditor('update_invoice_settings');
        $editor->givePermissionTo(Permission::firstOrCreate([
            'name' => 'view_invoice_settings', 'guard_name' => 'web',
        ]));
        $this->actingAs($editor, 'sanctum')->postJson('/api/settings/invoice', [
            'sub_show_meter' => false,
            'sub_lbl_meter' => 'عداد الاشتراك',
            'sub_inv_title_main' => 'فاتورة اشتراك جديدة',
            'site_favicon' => 'settings/invoices/icon.png',
            'whatsapp_access_token' => 'must-not-be-written-through-invoice-settings',
        ])->assertOk();
        $this->assertDatabaseHas('settings', ['key' => 'sub_lbl_meter', 'value' => 'عداد الاشتراك']);
        $this->assertDatabaseMissing('settings', ['key' => 'whatsapp_access_token']);
        $this->getJson('/api/settings/invoice')->assertOk()
            ->assertJsonPath('sub_lbl_meter', 'عداد الاشتراك')
            ->assertJsonPath('sub_inv_title_main', 'فاتورة اشتراك جديدة')
            ->assertJsonPath('site_favicon', 'settings/invoices/icon.png')
            ->assertJsonStructure(['sub_show_meter']);
    }

    public function test_invoice_image_upload_uses_page_permission_without_granting_general_settings(): void
    {
        Storage::fake('public');
        $editor = $this->auditor('Update:InvoiceSettings');
        $result = $this->actingAs($editor, 'sanctum')->post('/api/settings/upload-logo', [
            'key' => 'invoice_logo', 'file' => UploadedFile::fake()->image('logo.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertExists($result->json('path'));
        $this->post('/api/settings/upload-logo', [
            'key' => 'unrelated_private_image', 'file' => UploadedFile::fake()->image('other.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertDatabaseMissing('settings', ['key' => 'unrelated_private_image']);
    }

    public function test_receipt_editor_cannot_upload_the_invoice_only_favicon(): void
    {
        Storage::fake('public');
        $editor = $this->auditor('Update:ReceiptSettings');
        $this->actingAs($editor, 'sanctum')->post('/api/settings/upload-logo', [
            'key' => 'invoice_watermark', 'file' => UploadedFile::fake()->image('watermark.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/settings/upload-logo', [
            'key' => 'site_favicon', 'file' => UploadedFile::fake()->image('favicon.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }
}
