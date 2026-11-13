<?php

namespace Tests\Feature;

use App\Models\Meter;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SingleCurrentMeterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
    }

    private function subscriber(string $name = 'المشترك'): Subscriber
    {
        $square = Square::create(['name' => 'المربع - '.$name]);

        return Subscriber::create([
            'name' => $name,
            'square_id' => $square->id,
            'status' => 'active',
        ]);
    }

    public function test_api_refuses_a_second_meter_even_when_the_first_is_inactive(): void
    {
        $subscriber = $this->subscriber();
        $first = $this->postJson('/api/meters', [
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'A-100',
            'status' => 'inactive',
        ])->assertCreated()->json('meter.id');

        $this->postJson('/api/meters', [
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'A-200',
        ])->assertUnprocessable()->assertJsonValidationErrors('subscriber_id');

        $this->assertDatabaseCount('meters', 1);
        $this->assertSame($first, $subscriber->fresh()->currentMeter->id);
    }

    public function test_database_blocks_double_assignment_even_when_bypassing_controllers(): void
    {
        $subscriber = $this->subscriber();
        Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'FIRST']);

        $this->expectException(QueryException::class);
        Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'SECOND']);
    }

    public function test_retired_meter_stays_in_history_and_replacement_is_current(): void
    {
        $subscriber = $this->subscriber();
        $old = $this->postJson('/api/meters', [
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'M-OLD',
            'path_number' => 4,
        ])->assertCreated()->json('meter.id');

        $this->putJson('/api/meters/'.$old, [
            'removed_at' => '2026-11-01',
            'status' => 'inactive',
        ])->assertOk();

        $new = $this->postJson('/api/meters', [
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'M-NEW',
            'path_number' => 4,
        ])->assertCreated()->json('meter.id');

        $this->assertSame($new, $subscriber->fresh()->currentMeter->id);
        $this->assertSame(1, $subscriber->historicalMeters()->count());
        $this->assertDatabaseHas('meters', [
            'id' => $old,
            'subscriber_id' => $subscriber->id,
            'removed_at' => '2026-11-01 00:00:00',
        ]);

        $this->getJson('/api/meters?subscriber_id='.$subscriber->id)
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $new);
        $this->getJson('/api/subscribers')->assertOk()
            ->assertJsonPath('data.0.current_meter_id', $new)
            ->assertJsonPath('data.0.meter_number', 'M-NEW')
            ->assertJsonPath('data.0.path_numbers', [4]);
    }

    public function test_owner_cannot_change_if_meter_has_financial_history(): void
    {
        $original = $this->subscriber('القديم');
        $destination = $this->subscriber('الجديد');
        $meter = Meter::create([
            'subscriber_id' => $original->id,
            'unique_code' => 'ACCOUNTED-METER',
        ]);
        Transaction::create([
            'subscriber_id' => $original->id,
            'meter_id' => $meter->id,
            'type' => 'invoice',
            'amount' => -50,
        ]);

        $this->putJson('/api/meters/'.$meter->id, [
            'subscriber_id' => $destination->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('subscriber_id');
        $this->assertSame($original->id, $meter->fresh()->subscriber_id);
        $this->assertDatabaseHas('transactions', [
            'subscriber_id' => $original->id,
            'meter_id' => $meter->id,
        ]);
    }

    public function test_owner_cannot_move_to_subscriber_with_a_current_meter(): void
    {
        $first = $this->subscriber('واحد');
        $second = $this->subscriber('اثنان');
        $meterA = Meter::create(['subscriber_id' => $first->id, 'unique_code' => 'ONE']);
        Meter::create(['subscriber_id' => $second->id, 'unique_code' => 'TWO']);

        $this->putJson('/api/meters/'.$meterA->id, [
            'subscriber_id' => $second->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('subscriber_id');
        $this->assertSame($first->id, $meterA->fresh()->subscriber_id);
    }

    public function test_unbilled_reading_blocks_ending_meter_assignment(): void
    {
        $subscriber = $this->subscriber();
        $meter = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'PENDING-READING',
        ]);

        $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'current_reading' => 15,
            'reading_date' => '2026-11-12',
        ])->assertCreated();

        $this->putJson('/api/meters/'.$meter->id, [
            'removed_at' => '2026-11-12',
        ])->assertUnprocessable()->assertJsonValidationErrors('removed_at');
        $this->assertNull($meter->fresh()->removed_at);
    }

    public function test_historical_meter_cannot_be_reactivated_while_another_is_current(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'OLD-REMOVED',
            'removed_at' => '2026-10-01',
        ]);
        $current = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'NEW-CURRENT',
        ]);

        $this->putJson('/api/meters/'.$old->id, [
            'removed_at' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('subscriber_id');

        $this->assertNotNull($old->fresh()->removed_at);
        $this->assertSame($current->id, $subscriber->fresh()->currentMeter->id);
    }

    public function test_new_consumption_invoice_resolves_the_single_current_meter(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'OLD-CONSUMPTION',
            'removed_at' => '2026-10-01',
        ]);
        $current = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'CURRENT-CONSUMPTION',
        ]);

        $this->postJson('/api/invoices', [
            'subscriber_id' => $subscriber->id,
            'invoice_type' => 'meter',
            'consumption_fee' => 120,
        ])->assertCreated()
            ->assertJsonPath('invoice.meter_id', $current->id);

        $this->assertDatabaseMissing('invoices', [
            'meter_id' => $old->id,
            'consumption_fee' => 120,
        ]);
    }

    public function test_consumption_invoice_without_a_current_meter_is_rejected(): void
    {
        $subscriber = $this->subscriber();
        Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'ONLY-HISTORIC',
            'removed_at' => '2026-10-01',
        ]);

        $this->postJson('/api/invoices', [
            'subscriber_id' => $subscriber->id,
            'invoice_type' => 'meter',
            'consumption_fee' => 20,
        ])->assertUnprocessable()->assertJsonValidationErrors('meter_id');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_non_consumption_service_invoice_can_refer_to_historic_meter(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'HISTORIC-SERVICE',
            'removed_at' => '2026-10-01',
        ]);

        $this->postJson('/api/invoices', [
            'subscriber_id' => $subscriber->id,
            'meter_id' => $old->id,
            'invoice_type' => 'subscription',
            'subscription_installment' => 15,
        ])->assertCreated()->assertJsonPath('invoice.meter_id', $old->id);
    }

    public function test_filament_manual_consumption_invoice_uses_current_meter_not_history(): void
    {
        $subscriber = $this->subscriber();
        Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'FILAMENT-RETIRED',
            'removed_at' => '2026-09-01',
        ]);
        $current = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'FILAMENT-CURRENT',
        ]);

        // Exercise the form-to-record validation without requiring a browser.
        $reflection = new \ReflectionClass(\App\Filament\Resources\InvoiceResource\Pages\CreateInvoice::class);
        $page = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('mutateFormDataBeforeCreate');

        $data = $method->invoke($page, [
            'subscriber_id' => $subscriber->id,
            'consumption_fee' => 34,
        ]);
        $this->assertSame($current->id, $data['meter_id']);
        $this->assertSame('meter', $data['invoice_type']);
        $this->assertSame('issued', $data['status']);
    }

    public function test_filament_manual_consumption_without_current_meter_fails(): void
    {
        $subscriber = $this->subscriber();
        $reflection = new \ReflectionClass(\App\Filament\Resources\InvoiceResource\Pages\CreateInvoice::class);
        $page = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('mutateFormDataBeforeCreate');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $method->invoke($page, [
            'subscriber_id' => $subscriber->id,
            'consumption_fee' => 25,
        ]);
    }

    public function test_manual_consumption_invoice_cannot_target_a_retired_meter(): void
    {
        $subscriber = $this->subscriber();
        $meter = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'INVOICE-RETIRED',
            'removed_at' => '2026-10-01',
        ]);
        $this->postJson('/api/invoices', [
            'subscriber_id' => $subscriber->id,
            'meter_id' => $meter->id,
            'invoice_type' => 'meter',
            'consumption_fee' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('meter_id');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_consumption_service_rejects_invoice_for_retired_meter(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'GENERATE-RETIRED',
            'removed_at' => '2026-10-01',
        ]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\InvoiceService::class)->generateInvoice(
            subscriber: $subscriber,
            meter: $old,
        );
    }

    public function test_atomic_replacement_retains_history_balances_and_audit(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'OLD-ATOMIC',
            'balance' => '-73.50',
            'path_number' => 11,
        ]);

        $response = $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'عداد تالف يحتاج استبدالاً',
            'new_meter' => [
                'unique_code' => 'NEW-ATOMIC',
                'path_number' => 11,
                'initial_reading' => 0,
            ],
        ])->assertCreated()->assertJsonPath('old_meter.id', $old->id)
            ->assertJsonPath('current_meter.unique_code', 'NEW-ATOMIC');

        $newId = $response->json('current_meter.id');
        $this->assertSame($newId, $subscriber->fresh()->currentMeter->id);
        $this->assertSame(1, $subscriber->historicalMeters()->count());
        $this->assertSame('-73.50', (string) $old->fresh()->balance);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'meter.replaced',
            'entity_id' => (string) $old->id,
        ]);
    }

    public function test_historic_invoice_can_still_be_paid_after_meter_replacement(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'OLD-DEBT']);

        $invoice = $this->postJson('/api/invoices', [
            'subscriber_id' => $subscriber->id,
            'meter_id' => $old->id,
            'invoice_type' => 'meter',
            'consumption_fee' => 100,
        ])->assertCreated()->json('invoice.id');

        $new = $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'تغيير بسبب تلف العداد',
            'new_meter' => ['unique_code' => 'NEW-DEBT'],
        ])->assertCreated()->json('current_meter.id');

        $this->postJson('/api/payments', [
            'subscriber_id' => $subscriber->id,
            'meter_id' => $old->id,
            'invoice_id' => $invoice,
            'amount' => 40,
            'payment_method' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('payment.meter_id', $old->id);

        $this->assertSame($old->id, (int) \App\Models\Invoice::findOrFail($invoice)->meter_id);
        $this->assertSame($new, $subscriber->fresh()->currentMeter->id);
        $this->assertSame('0.00', (string) Meter::findOrFail($new)->balance);
        $this->assertSame('-60.00', (string) $old->fresh()->balance);
    }

    public function test_replacement_rolls_back_retirement_if_new_meter_path_conflicts(): void
    {
        $subscriber = $this->subscriber('الأول');
        $another = $this->subscriber('الثاني');
        $another->update(['square_id' => $subscriber->square_id]);

        $old = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'OLD-ROLLBACK',
            'path_number' => 4,
        ]);
        Meter::create([
            'subscriber_id' => $another->id,
            'unique_code' => 'OTHER-ROLLBACK',
            'path_number' => 9,
        ]);

        $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'بديل جديد',
            'new_meter' => ['unique_code' => 'FAIL-ROLLBACK', 'path_number' => 9],
        ])->assertUnprocessable()->assertJsonValidationErrors('path_number');

        $this->assertNull($old->fresh()->removed_at);
        $this->assertSame('active', $old->fresh()->status);
        $this->assertSame($old->id, $subscriber->fresh()->currentMeter->id);
        $this->assertDatabaseMissing('meters', ['unique_code' => 'FAIL-ROLLBACK']);
        $this->assertDatabaseMissing('audit_events', [
            'event' => 'meter.replaced',
            'entity_id' => (string) $old->id,
        ]);
    }

    public function test_replacement_rejects_pending_readings(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'PENDING-SWAP']);
        $this->postJson('/api/readings', [
            'meter_id' => $old->id,
            'current_reading' => 7,
            'reading_date' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'لا يمكن الاستبدال قبل الفوترة',
            'new_meter' => ['unique_code' => 'NEW-PENDING'],
        ])->assertUnprocessable()->assertJsonValidationErrors('meter_id');

        $this->assertNull($old->fresh()->removed_at);
        $this->assertDatabaseMissing('meters', ['unique_code' => 'NEW-PENDING']);
    }

    public function test_offline_batch_marks_retired_meter_failure_non_retryable(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'OFFLINE-OLD',
            'removed_at' => '2026-10-01',
        ]);

        $this->postJson('/api/readings/sync-batch', [
            'readings' => [[
                'meter_id' => $old->id,
                'reading_date' => now()->toDateString(),
                'current_reading' => 55,
                'sync_uuid' => (string) \Illuminate\Support\Str::uuid(),
            ]],
        ])->assertOk()
            ->assertJsonPath('success_count', 0)
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('results.0.success', false)
            ->assertJsonPath('results.0.retryable', false);
        $this->assertDatabaseCount('readings', 0);
    }

    public function test_new_reading_on_removed_meter_is_rejected(): void
    {
        $subscriber = $this->subscriber();
        $meter = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'RETIRED',
            'removed_at' => '2026-10-01',
        ]);

        $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'current_reading' => 50,
            'reading_date' => '2026-11-12',
        ])->assertUnprocessable()->assertJsonValidationErrors('meter_id');
    }
}
