<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * اعتماد الإلغاءات الكبيرة (خطة 2.0 — الفصل 5.3، بطاقة S4-04).
 *
 * القاعدة: السند لا يُحذف أبداً. تحت الحد يُلغى فوراً، وفوقه ينتظر مشرفاً.
 */
class PaymentVoidApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $collector;
    private User $supervisor;
    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'collector', 'guard_name' => 'web']);

        $this->collector = User::factory()->create(['role' => 'collector']);
        $this->collector->assignRole('collector');

        $this->supervisor = User::factory()->create(['role' => 'admin']);
        $this->supervisor->assignRole('super_admin');

        $square = Square::create(['name' => 'المربع الأول']);
        $subscriber = Subscriber::create([
            'name' => 'مشترك إلغاء',
            'square_id' => $square->id,
            'status' => 'active',
            'phone' => '777111222',
        ]);
        $meter = Meter::create([
            'unique_code' => 'VOID-MTR-1',
            'path_number' => 1,
            'subscriber_id' => $subscriber->id,
            'initial_reading' => 0,
        ]);
        $invoice = Invoice::create([
            'subscriber_id' => $subscriber->id,
            'meter_id' => $meter->id,
            'bill_number' => 'INV-VOID-1',
            'bill_date' => now()->toDateString(),
            'consumption_fee' => 4000,
            'total_amount' => 4000,
            'amount_paid' => 0,
            'status' => 'issued',
        ]);

        app(\App\Services\TransactionService::class)->recordTransaction(
            $subscriber, 'invoice', -4000, reference: $invoice, meter: $meter
        );
        $this->payment = app(\App\Services\PaymentService::class)->collectPayment(
            $subscriber, 1000, $invoice->id, userId: $this->collector->id
        );
    }

    private function threshold(float $amount): void
    {
        Setting::set('void_approval_threshold', (string) $amount, 'حد الإلغاء');
    }

    public function test_small_receipt_is_voided_immediately(): void
    {
        $this->threshold(5000);

        $this->actingAs($this->collector, 'sanctum')
            ->postJson("/api/payments/{$this->payment->id}/void", [
                'reason' => 'سُجّل السند على مشترك خطأ أثناء التحصيل',
            ])
            ->assertOk()
            ->assertJsonPath('pending_approval', false);

        $this->assertNotNull($this->payment->fresh()->voided_at);
        $this->assertNull($this->payment->fresh()->void_requested_at);
    }

    public function test_large_receipt_waits_for_supervisor_and_stays_valid(): void
    {
        $this->threshold(500);

        $this->actingAs($this->collector, 'sanctum')
            ->postJson("/api/payments/{$this->payment->id}/void", [
                'reason' => 'سند مكرر بسبب انقطاع الشبكة في الميدان',
            ])
            ->assertStatus(202)
            ->assertJsonPath('pending_approval', true);

        $fresh = $this->payment->fresh();
        $this->assertNull($fresh->voided_at, 'لا يُلغى السند قبل قرار المشرف');
        $this->assertNotNull($fresh->void_requested_at);
        $this->assertSame($this->collector->id, (int) $fresh->void_requested_by);
        $this->assertTrue(
            AuditEvent::where('event', 'payment.void_requested')
                ->where('entity_id', (string) $fresh->id)
                ->exists(),
        );
    }

    public function test_pending_request_is_idempotent(): void
    {
        $this->threshold(500);

        $payload = ['reason' => 'سند مكرر بسبب إعادة إرسال الحزمة مرتين'];
        $this->actingAs($this->collector, 'sanctum')
            ->postJson("/api/payments/{$this->payment->id}/void", $payload)
            ->assertStatus(202);
        $firstRequestedAt = $this->payment->fresh()->void_requested_at;

        $this->actingAs($this->collector, 'sanctum')
            ->postJson("/api/payments/{$this->payment->id}/void", $payload)
            ->assertStatus(202);

        $this->assertEquals(
            $firstRequestedAt,
            $this->payment->fresh()->void_requested_at,
            'إعادة الطلب لا تُغيّر وقت الطلب الأصلي',
        );
    }

    public function test_supervisor_approval_voids_and_reports(): void
    {
        $this->threshold(500);
        $this->actingAs($this->collector, 'sanctum')
            ->postJson("/api/payments/{$this->payment->id}/void", [
                'reason' => 'مبلغ أكبر من المتبقي على الفاتورة',
            ])
            ->assertStatus(202);

        $this->actingAs($this->supervisor, 'sanctum')
            ->getJson('/api/payments/void-requests')
            ->assertOk()
            ->assertJsonFragment(['receipt_number' => 'REC-TEST-0001']);

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/payments/{$this->payment->id}/void-decision", [
                'approve' => true,
                'notes' => 'تم التحقق مع المحصّل والمشترك.',
            ])
            ->assertOk();

        $fresh = $this->payment->fresh();
        $this->assertNotNull($fresh->voided_at);
        $this->assertSame('approval', $fresh->voided_via);
        $this->assertSame($this->supervisor->id, (int) $fresh->void_decided_by);
        $this->assertTrue(
            AuditEvent::where('event', 'payment.void_approved')
                ->where('entity_id', (string) $fresh->id)
                ->exists(),
        );
    }

    public function test_supervisor_rejection_keeps_the_receipt_valid(): void
    {
        $this->threshold(500);
        $this->actingAs($this->collector, 'sanctum')
            ->postJson("/api/payments/{$this->payment->id}/void", [
                'reason' => 'طلب إلغاء غير مبرر من المحصّل',
            ])
            ->assertStatus(202);

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/payments/{$this->payment->id}/void-decision", [
                'approve' => false,
                'notes' => 'السند مطابق لإيصال المشترك، لا يُلغى.',
            ])
            ->assertOk();

        $fresh = $this->payment->fresh();
        $this->assertNull($fresh->voided_at);
        $this->assertNull($fresh->void_requested_at, 'يُمسح الطلب بعد الرفض');
        $this->assertSame('السند مطابق لإيصال المشترك، لا يُلغى.', $fresh->void_decision_notes);
        $this->assertTrue(
            AuditEvent::where('event', 'payment.void_rejected')
                ->where('entity_id', (string) $fresh->id)
                ->exists(),
        );
    }

    public function test_collector_cannot_approve_own_request(): void
    {
        $this->threshold(500);
        $this->actingAs($this->collector, 'sanctum')
            ->postJson("/api/payments/{$this->payment->id}/void", [
                'reason' => 'محاولة اعتماد ذاتي لطلب الإلغاء',
            ])
            ->assertStatus(202);

        $this->actingAs($this->collector, 'sanctum')
            ->postJson("/api/payments/{$this->payment->id}/void-decision", [
                'approve' => true,
            ])
            ->assertForbidden();

        $this->assertNull($this->payment->fresh()->voided_at);
    }

    public function test_deciding_without_a_pending_request_is_rejected(): void
    {
        $this->threshold(500);

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/payments/{$this->payment->id}/void-decision", [
                'approve' => true,
            ])
            ->assertStatus(422);
    }
}
