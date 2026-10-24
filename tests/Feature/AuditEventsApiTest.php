<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * استقبال دفعات سجل التدقيق من التطبيق (خطة 2.0 — S4-05).
 *
 * القاعدة الأمنية: الفاعل هو المستخدم المُصادَق دائماً، ولا يستطيع العميل
 * انتحال فاعل آخر ولا تزوير وقت مستقبلي يخرب تسلسل السجل.
 */
class AuditEventsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;
    private User $collector;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'collector', 'guard_name' => 'web']);

        $this->supervisor = User::factory()->create(['role' => 'admin']);
        $this->supervisor->assignRole('super_admin');

        $this->collector = User::factory()->create(['role' => 'collector']);
        $this->collector->assignRole('collector');
    }

    public function test_mobile_batch_is_stored_with_the_authenticated_actor(): void
    {
        $response = $this->actingAs($this->collector, 'sanctum')
            ->postJson('/api/audit-events', [
                'events' => [
                    [
                        'event' => 'cash_session.opened',
                        'entity_type' => 'CashSession',
                        'entity_id' => '9',
                        'after' => ['opening_amount' => 0],
                        'occurred_at' => now()->subMinutes(5)->toIso8601String(),
                        'device_id' => 'device-001',
                        // محاولة انتحال: يجب أن تُتجاهل تماماً.
                        'actor_user_id' => $this->supervisor->id,
                    ],
                    [
                        'event' => 'payment.void_requested',
                        'entity_type' => 'Payment',
                        'entity_id' => '12',
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('stored', 2);

        $this->assertSame(2, AuditEvent::count());

        $opened = AuditEvent::where('event', 'cash_session.opened')->firstOrFail();
        $this->assertSame($this->collector->id, (int) $opened->actor_user_id);
        $this->assertSame('mobile', $opened->source);
        $this->assertSame('device-001', $opened->device_id);
        $this->assertSame('CashSession', $opened->entity_type);
        $this->assertSame('9', $opened->entity_id);

        $this->assertTrue(
            AuditEvent::where('event', 'payment.void_requested')
                ->where('actor_user_id', $this->collector->id)
                ->exists(),
        );
        $this->assertSame(0, AuditEvent::where('actor_user_id', $this->supervisor->id)->count());
        $this->assertGreaterThan(0, $response->json('stored'));
    }

    public function test_batch_is_limited_to_one_hundred_events(): void
    {
        $events = array_fill(0, 101, ['event' => 'reading.saved']);

        $this->actingAs($this->collector, 'sanctum')
            ->postJson('/api/audit-events', ['events' => $events])
            ->assertStatus(422)
            ->assertJsonValidationErrors('events');

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_empty_batch_is_rejected(): void
    {
        $this->actingAs($this->collector, 'sanctum')
            ->postJson('/api/audit-events', ['events' => []])
            ->assertStatus(422);

        $this->actingAs($this->collector, 'sanctum')
            ->postJson('/api/audit-events', [])
            ->assertStatus(422);
    }

    public function test_anonymous_devices_cannot_write_audit_events(): void
    {
        $this->postJson('/api/audit-events', [
            'events' => [['event' => 'cash_session.opened']],
        ])->assertUnauthorized();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_reading_the_audit_log_requires_supervisor_permissions(): void
    {
        AuditEvent::create([
            'event' => 'cash_session.opened',
            'actor_user_id' => $this->collector->id,
            'source' => 'mobile',
            'occurred_at' => now(),
        ]);

        $this->actingAs($this->collector, 'sanctum')
            ->getJson('/api/audit-events')
            ->assertForbidden();

        $this->actingAs($this->supervisor, 'sanctum')
            ->getJson('/api/audit-events')
            ->assertOk()
            ->assertJsonFragment(['event' => 'cash_session.opened']);
    }
}
