<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Acceptance tests for the owner-requested irreversible meter data cleanup. */
class StrictSingleMeterPurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
    }

    public function test_new_schema_has_no_historical_current_meter_virtual_key(): void
    {
        $this->assertFalse(Schema::hasColumn('meters', 'current_subscriber_id'));
    }

    public function test_replacement_removes_every_meter_linked_record_without_affecting_general_subscriber_fees(): void
    {
        $square = Square::create(['name' => 'Purge regression square']);
        $subscriber = Subscriber::create([
            'name' => 'Purge regression subscriber',
            'square_id' => $square->id,
            'status' => 'active',
        ]);
        $old = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'PURGE-OLD',
            'path_number' => 7,
        ]);

        $invoice = DB::table('invoices')->insertGetId([
            'subscriber_id' => $subscriber->id,
            'meter_id' => $old->id,
            'bill_number' => 'PURGE-INV',
            'bill_date' => '2026-11-14',
            'invoice_type' => 'meter',
            'total_amount' => 95,
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $invoice,
            'type' => 'consumption',
            'description' => 'Old meter consumption',
            'amount' => 95,
        ]);
        $cashSessionId = DB::table('cash_sessions')->insertGetId([
            'user_id' => User::query()->value('id'),
            'session_date' => '2026-11-14',
            'opening_amount' => 0,
            'expected_amount' => 50,
            'counted_amount' => 50,
            'variance_amount' => 0,
            'state' => 'closed',
        ]);
        $payment = DB::table('payments')->insertGetId([
            'subscriber_id' => $subscriber->id,
            'cash_session_id' => $cashSessionId,
            'meter_id' => $old->id,
            'invoice_id' => $invoice,
            'receipt_number' => 'PURGE-REC',
            'payment_date' => '2026-11-14',
            'payment_method' => 'cash',
            'amount' => 50,
        ]);
        DB::table('transactions')->insert([
            'subscriber_id' => $subscriber->id,
            'meter_id' => $old->id,
            'type' => 'invoice',
            'amount' => -95,
            'reference_type' => Invoice::class,
            'reference_id' => $invoice,
        ]);
        DB::table('transactions')->insert([
            'subscriber_id' => $subscriber->id,
            'meter_id' => $old->id,
            'type' => 'payment',
            'amount' => 50,
            'reference_type' => Payment::class,
            'reference_id' => $payment,
        ]);
        DB::table('readings')->insert([
            'meter_id' => $old->id,
            'reading_date' => '2026-11-14',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-14',
            'current_reading' => 30,
            'previous_reading' => 10,
            'consumption_units' => 20,
            'is_invoiced' => true,
            'invoice_id' => $invoice,
        ]);
        DB::table('subscription_fees')->insert([
            'subscriber_id' => $subscriber->id,
            'meter_id' => $old->id,
            'invoice_id' => $invoice,
            'amount' => 15,
        ]);
        DB::table('service_requests')->insert([
            'subscriber_id' => $subscriber->id,
            'invoice_id' => $invoice,
            'amount' => 20,
            'service_date' => '2026-11-14',
        ]);
        DB::table('reading_faults')->insert([
            'subscriber_id' => $subscriber->id,
            'meter_id' => $old->id,
            'reading_date' => '2026-11-14',
            'fault_type' => 'no_access',
            'sync_uuid' => (string) Str::uuid(),
        ]);
        DB::table('route_visits')->insert([
            'meter_id' => $old->id,
            'route_date' => '2026-11-14',
            'status' => 'visited',
            'sync_uuid' => (string) Str::uuid(),
        ]);
        DB::table('audit_events')->insert([
            'entity_type' => 'meter',
            'entity_id' => (string) $old->id,
            'event' => 'meter.old',
        ]);

        // Unrelated subscriber-wide general ledger data must not be removed.
        DB::table('transactions')->insert([
            'subscriber_id' => $subscriber->id,
            'meter_id' => null,
            'type' => 'adjustment',
            'amount' => 25,
        ]);

        $currentId = $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'حذف كامل لبيانات العداد التالف',
            'new_meter' => ['unique_code' => 'PURGE-NEW', 'path_number' => 7],
        ])->assertCreated()->json('current_meter.id');

        $this->assertNotSame($old->id, $currentId);
        $this->assertDatabaseMissing('meters', ['id' => $old->id]);
        $this->assertDatabaseHas('meters', ['id' => $currentId, 'subscriber_id' => $subscriber->id]);
        $this->assertDatabaseCount('meters', 1);
        foreach ([
            'invoices', 'invoice_items', 'payments', 'readings', 'subscription_fees',
            'service_requests', 'reading_faults', 'route_visits',
        ] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('cash_sessions', [
            'id' => $cashSessionId,
            'state' => 'closed',
            'expected_amount' => 0,
            'counted_amount' => 50,
            'variance_amount' => 50,
        ]);
        $this->assertSame('25.00', (string) $subscriber->fresh()->balance);
        $this->assertDatabaseMissing('audit_events', [
            'entity_type' => 'meter',
            'entity_id' => (string) $old->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'meter',
            'entity_id' => (string) $currentId,
            'event' => 'meter.replaced',
        ]);
    }

    public function test_replacement_does_not_delete_a_different_subscribers_meter_or_bills(): void
    {
        $square = Square::create(['name' => 'Purge scope square']);
        $owner = Subscriber::create(['name' => 'Owner', 'square_id' => $square->id]);
        $other = Subscriber::create(['name' => 'Other', 'square_id' => $square->id]);
        $old = Meter::create(['subscriber_id' => $owner->id, 'unique_code' => 'SCOPE-OLD']);
        $untouched = Meter::create(['subscriber_id' => $other->id, 'unique_code' => 'SCOPE-KEEP']);
        $invoice = DB::table('invoices')->insertGetId([
            'subscriber_id' => $other->id,
            'meter_id' => $untouched->id,
            'bill_number' => 'SCOPE-KEEP-INV',
            'bill_date' => '2026-11-14',
            'invoice_type' => 'meter',
        ]);

        $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'اختبار منع حذف بيانات مشترك آخر',
            'new_meter' => ['unique_code' => 'SCOPE-NEW'],
        ])->assertCreated();

        $this->assertDatabaseHas('meters', ['id' => $untouched->id, 'subscriber_id' => $other->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice, 'meter_id' => $untouched->id]);
        $this->assertDatabaseCount('meters', 2);
    }
}
