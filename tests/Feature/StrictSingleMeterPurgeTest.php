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

/** Acceptance tests for one meter per subscriber with protected financial history. */
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

    public function test_replacement_cannot_destroy_financial_history_or_rewrite_closed_till(): void
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
            'status' => 'draft',
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
            'actual_reading_date' => '2026-11-14',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-14',
            'current_reading' => 30,
            'previous_reading' => 10,
            'consumption_units' => 20,
            'is_invoiced' => true,
            'invoice_id' => $invoice,
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

        $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'اختبار حماية السجل المالي',
            'new_meter' => ['unique_code' => 'PURGE-NEW', 'path_number' => 7],
        ])->assertUnprocessable()->assertJsonValidationErrors('meter_id');

        $this->assertDatabaseHas('meters', ['id' => $old->id]);
        $this->assertDatabaseCount('meters', 1);
        $this->assertDatabaseHas('invoices', ['id' => $invoice, 'meter_id' => $old->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment, 'meter_id' => $old->id]);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseCount('readings', 1);
        $this->assertDatabaseCount('reading_faults', 1);
        $this->assertDatabaseCount('route_visits', 1);
        $this->assertDatabaseCount('transactions', 3);
        $this->assertDatabaseHas('cash_sessions', [
            'id' => $cashSessionId, 'state' => 'closed', 'expected_amount' => 50,
            'counted_amount' => 50, 'variance_amount' => 0,
        ]);
        $this->assertSame(-20.0, $subscriber->getAccountBalance());
        $this->assertDatabaseHas('audit_events', ['entity_type' => 'meter', 'entity_id' => (string) $old->id]);
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

    public function test_replacement_cannot_remove_a_closed_till_receipt(): void
    {
        $square = Square::create(['name' => 'Cash reconciliation square']);
        $owner = Subscriber::create(['name' => 'Old owner', 'square_id' => $square->id]);
        $other = Subscriber::create(['name' => 'Different owner', 'square_id' => $square->id]);
        $oldMeter = Meter::create(['subscriber_id' => $owner->id, 'unique_code' => 'CASH-OLD']);
        $otherMeter = Meter::create(['subscriber_id' => $other->id, 'unique_code' => 'CASH-KEEP']);
        $session = DB::table('cash_sessions')->insertGetId([
            'user_id' => User::query()->value('id'),
            'session_date' => now()->toDateString(),
            'opening_amount' => 0,
            'expected_amount' => 120,
            'counted_amount' => 120,
            'variance_amount' => 0,
            'state' => 'closed',
        ]);
        $oldReceiptId = DB::table('payments')->insertGetId([
            'subscriber_id' => $owner->id,
            'meter_id' => $oldMeter->id,
            'cash_session_id' => $session,
            'receipt_number' => 'CASH-OLD-REC',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount' => 50,
        ]);
        $otherReceiptId = DB::table('payments')->insertGetId([
            'subscriber_id' => $other->id,
            'meter_id' => $otherMeter->id,
            'cash_session_id' => $session,
            'receipt_number' => 'CASH-OTHER-REC',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount' => 70,
        ]);

        $this->postJson('/api/meters/'.$oldMeter->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'حذف العداد القديم مع سند صندوق',
            'new_meter' => ['unique_code' => 'CASH-NEW'],
        ])->assertCreated();

        $this->assertDatabaseHas('payments', ['id' => $oldReceiptId, 'meter_id' => null, 'retired_meter_id' => $oldMeter->id]);
        $this->assertDatabaseHas('payments', ['id' => $otherReceiptId, 'meter_id' => $otherMeter->id]);
        $this->assertDatabaseHas('cash_sessions', [
            'id' => $session,
            'state' => 'closed',
            'expected_amount' => 120,
            'counted_amount' => 120,
            'variance_amount' => 0,
        ]);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('meters', 2);
    }

    public function test_replacement_cannot_remove_an_open_till_receipt(): void
    {
        $square = Square::create(['name' => 'Open cash square']);
        $owner = Subscriber::create(['name' => 'Open cash owner', 'square_id' => $square->id]);
        $old = Meter::create(['subscriber_id' => $owner->id, 'unique_code' => 'OPEN-CASH-OLD']);
        $session = DB::table('cash_sessions')->insertGetId([
            'user_id' => User::query()->value('id'),
            'session_date' => now()->toDateString(),
            'opening_amount' => 0,
            'state' => 'open',
        ]);
        DB::table('payments')->insert([
            'subscriber_id' => $owner->id,
            'meter_id' => $old->id,
            'cash_session_id' => $session,
            'receipt_number' => 'OPEN-CASH-REC',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount' => 25,
        ]);

        $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'اختبار صندوق مفتوح',
            'new_meter' => ['unique_code' => 'OPEN-CASH-NEW'],
        ])->assertCreated();

        $this->assertDatabaseHas('cash_sessions', [
            'id' => $session,
            'state' => 'open',
            'expected_amount' => null,
            'variance_amount' => null,
        ]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['receipt_number' => 'OPEN-CASH-REC', 'meter_id' => null, 'retired_meter_id' => $old->id]);
    }

    public function test_plain_meter_deletion_removes_fault_visit_and_meter_audit_rows_permanently(): void
    {
        $square = Square::create(['name' => 'Delete cleanup square']);
        $subscriber = Subscriber::create(['name' => 'Delete cleanup subscriber', 'square_id' => $square->id]);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'DELETE-ORPHAN-1']);
        DB::table('reading_faults')->insert([
            'meter_id' => $meter->id,
            'subscriber_id' => $subscriber->id,
            'reading_date' => now()->toDateString(),
            'fault_type' => 'no_access',
            'sync_uuid' => (string) Str::uuid(),
        ]);
        DB::table('route_visits')->insert([
            'meter_id' => $meter->id,
            'route_date' => now()->toDateString(),
            'status' => 'visited',
            'sync_uuid' => (string) Str::uuid(),
        ]);
        DB::table('audit_events')->insert([
            'entity_type' => 'meter',
            'entity_id' => (string) $meter->id,
            'event' => 'meter.old',
        ]);
        $this->deleteJson('/api/meters/'.$meter->id)->assertOk();

        foreach (['meters', 'reading_faults', 'route_visits', 'audit_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('subscribers', ['id' => $subscriber->id]);
    }

    public function test_bulk_delete_purges_obsolete_field_records_but_skips_financial_meters(): void
    {
        $square = Square::create(['name' => 'Bulk cleanup square']);
        $canDelete = Subscriber::create(['name' => 'Can delete', 'square_id' => $square->id]);
        $protected = Subscriber::create(['name' => 'Protected', 'square_id' => $square->id]);
        $empty = Meter::create(['subscriber_id' => $canDelete->id, 'unique_code' => 'BULK-EMPTY']);
        $invoiced = Meter::create(['subscriber_id' => $protected->id, 'unique_code' => 'BULK-INVOICED']);
        DB::table('reading_faults')->insert([
            'meter_id' => $empty->id,
            'subscriber_id' => $canDelete->id,
            'reading_date' => now()->toDateString(),
            'fault_type' => 'no_access',
            'sync_uuid' => (string) Str::uuid(),
        ]);
        $invoice = DB::table('invoices')->insertGetId([
            'subscriber_id' => $protected->id,
            'meter_id' => $invoiced->id,
            'bill_number' => 'BULK-PROTECTED-INV',
            'bill_date' => now()->toDateString(),
            'invoice_type' => 'meter',
        ]);
        $this->postJson('/api/meters/bulk-delete', [
            'ids' => [$empty->id, $invoiced->id],
        ])->assertOk()
          ->assertJsonPath('deleted_count', 1)
          ->assertJsonPath('skipped_count', 1)
          ->assertJsonPath('skipped_ids', [$invoiced->id]);

        $this->assertDatabaseMissing('meters', ['id' => $empty->id]);
        $this->assertDatabaseHas('meters', ['id' => $invoiced->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice, 'meter_id' => $invoiced->id]);
        $this->assertDatabaseCount('reading_faults', 0);
    }
}
