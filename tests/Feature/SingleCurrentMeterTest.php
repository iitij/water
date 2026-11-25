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
            'subscription_start_date' => '2026-01-01',
        ]);
    }

    private function measuredConsumption(Meter $meter, int $units): void
    {
        \App\Models\Tariff::create(['name' => 'Synthetic measured tariff', 'unit_price' => 1, 'is_active' => true]);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01',
            'previous_reading' => 0, 'current_reading' => $units]);
    }

    public function test_meter_schema_has_no_obsolete_identity_columns(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('meters', 'ten'.'ant_name'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('meters', 'ten'.'ant_phone'));
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

    public function test_replacement_keeps_only_the_new_physical_meter(): void
    {
        $subscriber = $this->subscriber();
        $old = $this->postJson('/api/meters', [
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'M-OLD',
            'path_number' => 4,
        ])->assertCreated()->json('meter.id');

        $new = $this->postJson('/api/meters/'.$old.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'تغيير عداد قديم',
            'new_meter' => ['unique_code' => 'M-NEW', 'path_number' => 4],
        ])->assertCreated()->json('current_meter.id');

        $this->assertNotSame($old, $new);
        $this->assertDatabaseMissing('meters', ['id' => $old]);
        $this->assertDatabaseCount('meters', 1);
        $this->assertSame($new, $subscriber->fresh()->currentMeter->id);
        $this->getJson('/api/meters?subscriber_id='.$subscriber->id)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $new);
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

    public function test_unbilled_reading_cannot_be_removed_through_ordinary_meter_edit(): void
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

    public function test_database_blocks_a_second_meter_even_when_first_was_retired(): void
    {
        $subscriber = $this->subscriber();
        Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'OLD-REMOVED',
            'removed_at' => '2026-10-01',
        ]);

        $this->expectException(QueryException::class);
        Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'NEW-CURRENT']);
    }

    public function test_new_consumption_invoice_resolves_the_only_meter(): void
    {
        $subscriber = $this->subscriber();
        $current = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'CURRENT-CONSUMPTION',
        ]);
        $this->measuredConsumption($current, 120);

        $this->postJson('/api/invoices', [
            'subscriber_id' => $subscriber->id,
            'invoice_type' => 'meter',
            'consumption_fee' => 120,
        ])->assertCreated()->assertJsonPath('invoice.meter_id', $current->id);
        $this->assertDatabaseCount('meters', 1);
    }

    public function test_consumption_invoice_without_an_assigned_meter_is_rejected(): void
    {
        $subscriber = $this->subscriber();

        $this->postJson('/api/invoices', [
            'subscriber_id' => $subscriber->id,
            'invoice_type' => 'meter',
            'consumption_fee' => 20,
        ])->assertUnprocessable()->assertJsonValidationErrors('meter_id');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_retired_fee_only_invoice_is_rejected(): void
    {
        $subscriber = $this->subscriber();
        $meter = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'SERVICE-METER',
        ]);

        $this->postJson('/api/invoices', [
            'subscriber_id' => $subscriber->id,
            'meter_id' => $meter->id,
            'invoice_type' => 'subscription',
            'subscription_installment' => 15,
        ])->assertUnprocessable()->assertJsonValidationErrors('invoice_type');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_filament_manual_consumption_invoice_uses_only_meter(): void
    {
        $subscriber = $this->subscriber();
        $current = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'FILAMENT-CURRENT',
        ]);
        $this->measuredConsumption($current, 34);

        $reflection = new \ReflectionClass(\App\Filament\Resources\InvoiceResource\Pages\CreateInvoice::class);
        $page = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('handleRecordCreation');
        $data = $method->invoke($page, [
            'subscriber_id' => $subscriber->id,
            'consumption_fee' => 34,
        ]);
        $this->assertSame($current->id, $data->meter_id);
        $this->assertSame('meter', $data->invoice_type);
        $this->assertSame('issued', $data->status);
        $this->assertSame('34.00', $data->total_amount);
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $data->id, 'amount' => 34]);
    }

    public function test_filament_manual_consumption_without_current_meter_fails(): void
    {
        $subscriber = $this->subscriber();
        $reflection = new \ReflectionClass(\App\Filament\Resources\InvoiceResource\Pages\CreateInvoice::class);
        $page = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('handleRecordCreation');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $method->invoke($page, [
            'subscriber_id' => $subscriber->id,
            'consumption_fee' => 25,
        ]);
    }

    public function test_manual_consumption_invoice_cannot_target_a_deleted_meter(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'INVOICE-OLD',
        ]);
        $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'تغيير اختبار',
            'new_meter' => ['unique_code' => 'INVOICE-NEW'],
        ])->assertCreated();

        $this->postJson('/api/invoices', [
            'subscriber_id' => $subscriber->id,
            'meter_id' => $old->id,
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

    public function test_atomic_replacement_purges_history_and_writes_audit_for_new_meter(): void
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
        ])->assertCreated()->assertJsonPath('current_meter.unique_code', 'NEW-ATOMIC');

        $newId = $response->json('current_meter.id');
        $this->assertNotSame($old->id, $newId);
        $this->assertSame($newId, $subscriber->fresh()->currentMeter->id);
        $this->assertDatabaseCount('meters', 1);
        $this->assertDatabaseMissing('meters', ['id' => $old->id]);
        $this->assertSame('0.00', (string) Meter::findOrFail($newId)->balance);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'meter.replaced',
            'entity_id' => (string) $newId,
        ]);
    }

    public function test_old_meter_invoice_and_receipt_survive_replacement(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'OLD-DEBT']);
        $this->measuredConsumption($old, 100);

        $invoice = $this->postJson('/api/invoices', [
            'subscriber_id' => $subscriber->id,
            'meter_id' => $old->id,
            'invoice_type' => 'meter',
            'consumption_fee' => 100,
        ])->assertCreated()->json('invoice.id');

        $receipt = $this->postJson('/api/payments', [
            'subscriber_id' => $subscriber->id,
            'meter_id' => $old->id,
            'invoice_id' => $invoice,
            'amount' => 40,
            'payment_method' => 'cash',
        ])->assertCreated()->json('payment.id');

        $billed = $old->readings()->firstOrFail();
        $pending = \App\Models\Reading::create([
            'meter_id' => $old->id, 'reading_date' => '2026-03-01', 'current_reading' => '120.00',
        ]);
        $linesBefore = \Illuminate\Support\Facades\DB::table('invoice_tariff_lines')->orderBy('id')->get()->all();
        $balanceBefore = app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber);
        $financeBefore = $this->financialRowsWithoutMeterProvenance();

        $new = $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'حذف بيانات العداد التالف',
            'new_meter' => ['unique_code' => 'NEW-DEBT'],
        ])->assertCreated()->json('current_meter.id');

        $this->assertDatabaseMissing('meters', ['id' => $old->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice, 'meter_id' => null, 'retired_meter_id' => $old->id, 'total_amount' => 100, 'amount_paid' => 40]);
        $this->assertDatabaseHas('payments', ['id' => $receipt, 'meter_id' => null, 'retired_meter_id' => $old->id, 'amount' => 40]);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseMissing('readings', ['id' => $pending->id]);
        $this->assertDatabaseHas('readings', ['id' => $billed->id, 'meter_id' => null,
            'retired_meter_id' => $old->id, 'invoice_id' => $invoice, 'consumption_units' => 100]);
        $this->assertEquals($linesBefore, \Illuminate\Support\Facades\DB::table('invoice_tariff_lines')->orderBy('id')->get()->all());
        $this->assertSame($balanceBefore, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertEquals($financeBefore, $this->financialRowsWithoutMeterProvenance());
        $this->assertSame(60.0, $subscriber->getAccountDebt());
        $this->assertSame($new, $subscriber->fresh()->currentMeter->id);
        $this->assertSame('0.00', (string) Meter::findOrFail($new)->balance);
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

    public function test_replacement_discards_original_twenty_unit_pending_reading_without_creating_debt(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'PENDING-SWAP']);
        \App\Models\Setting::set('auto_issue_invoice_on_reading', '0');
        $readingId = $this->postJson('/api/readings', [
            'meter_id' => $old->id, 'current_reading' => '20.00', 'reading_date' => '2026-02-01',
        ])->assertCreated()->json('reading.id');
        $other = Meter::create(['subscriber_id' => $this->subscriber('Retained')->id, 'unique_code' => 'KEEP-PENDING']);
        $kept = \App\Models\Reading::create([
            'meter_id' => $other->id, 'reading_date' => '2026-02-01', 'current_reading' => '15.00',
        ]);
        $keptBefore = \Illuminate\Support\Facades\DB::table('readings')->where('id', $kept->id)->first();

        $this->deleteJson('/api/meters/'.$old->id)->assertConflict();
        $this->postJson('/api/meters/bulk-delete', ['ids' => [$old->id, $other->id]])
            ->assertOk()->assertJsonPath('deleted_count', 0)->assertJsonPath('skipped_count', 2);
        $this->assertDatabaseHas('readings', ['id' => $readingId, 'consumption_units' => 20, 'is_invoiced' => false]);
        try {
            $old->delete();
            $this->fail('Ordinary model deletion discarded a current observation.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('meter_id', $e->errors());
        }
        $this->assertNull($old->fresh()->deleted_at);


        $new = $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'استبدال عداد وإزالة القراءة غير المفوترة',
            'new_meter' => ['unique_code' => 'NEW-PENDING', 'initial_reading' => '100.25',
                'installed_at' => '2026-02-02'],
        ])->assertCreated()->json('current_meter.id');

        $this->assertDatabaseMissing('meters', ['id' => $old->id]);
        $this->assertDatabaseMissing('readings', ['id' => $readingId]);
        $this->assertEquals($keptBefore, \Illuminate\Support\Facades\DB::table('readings')->where('id', $kept->id)->first());
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(0, app(\App\Services\TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertSame($new, $subscriber->fresh()->currentMeter->id);
        $this->assertNotSame($old->id, $new);
        $reading = \App\Models\Reading::create([
            'meter_id' => $new, 'reading_date' => '2026-03-01', 'current_reading' => '100.26',
        ]);
        $this->assertSame('100.25', $reading->previous_reading);
        $this->assertSame('0.01', $reading->consumption_units);
        $this->assertSame('2026-02-02', $reading->period_start);
        $audit = \App\Models\AuditEvent::where('event', 'meter.replaced')->where('entity_id', (string) $new)->firstOrFail();
        $this->assertSame(1, $audit->after['discarded_unbilled_readings']);
    }

    public function test_replacement_rejects_ambiguous_unbilled_flag_with_financial_tariff_link(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'AMBIGUOUS-PENDING']);
        $this->measuredConsumption($old, 20);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $old);
        $reading = $old->readings()->firstOrFail();
        // Ordinary synthetic inconsistency: a historical price snapshot still protects the row.
        \Illuminate\Support\Facades\DB::table('readings')->where('id', $reading->id)
            ->update(['is_invoiced' => false, 'invoice_id' => null]);
        $before = $this->meterCleanupSnapshot();
        $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(), 'reason' => 'اختبار ارتباط مالي ملتبس',
            'new_meter' => ['unique_code' => 'AMBIGUOUS-NEW'],
        ])->assertUnprocessable()->assertJsonValidationErrors('meter_id');
        $this->assertEquals($before, $this->meterCleanupSnapshot());
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $invoice->id, 'reading_id' => $reading->id]);
    }

    public function test_failed_replacement_after_pending_deletion_restores_financial_rows_and_images(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'ATOMIC-OLD']);
        $this->measuredConsumption($old, 20);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $old);
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('readings/atomic-pending.jpg', 'synthetic');
        $pending = \App\Models\Reading::create([
            'meter_id' => $old->id, 'reading_date' => '2026-03-01',
            'current_reading' => '40.00', 'image_path' => 'readings/atomic-pending.jpg',
        ]);
        $before = $this->meterCleanupSnapshot();
        Meter::creating(function (Meter $new) use ($old, $pending, $invoice): void {
            if ($new->unique_code === 'ATOMIC-FAIL') {
                $this->assertDatabaseMissing('meters', ['id' => $old->id]);
                $this->assertDatabaseMissing('readings', ['id' => $pending->id]);
                $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'retired_meter_id' => $old->id]);
                \Illuminate\Support\Facades\Storage::disk('public')->assertExists('readings/atomic-pending.jpg');
                throw new \RuntimeException('Synthetic failure after retirement');
            }
        });
        try {
            app(\App\Services\SingleMeterService::class)->replace($old,
                ['unique_code' => 'ATOMIC-FAIL'], now()->toDateString(), 'Synthetic rollback');
            $this->fail('Replacement unexpectedly succeeded.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic failure after retirement', $e->getMessage());
        }
        $this->assertEquals($before, $this->meterCleanupSnapshot());
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists('readings/atomic-pending.jpg');
    }

    public function test_direct_financial_retirement_cannot_discard_pending_readings_without_replacement_intent(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'DIRECT-RETIRE']);
        $this->measuredConsumption($old, 20);
        app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $old);
        $pending = \App\Models\Reading::create([
            'meter_id' => $old->id, 'reading_date' => '2026-03-01', 'current_reading' => '40.00',
        ]);
        $before = $this->meterCleanupSnapshot();
        try {
            \Illuminate\Support\Facades\DB::transaction(fn () =>
                app(\App\Services\FinancialMeterRetirementService::class)->retire($old, $subscriber));
            $this->fail('Direct retirement discarded pending usage.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('meter_id', $e->errors());
        }
        $this->assertDatabaseHas('readings', ['id' => $pending->id]);
        $this->assertEquals($before, $this->meterCleanupSnapshot());
    }

    public function test_replacement_preserves_image_referenced_by_another_meter(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'IMAGE-OLD']);
        $other = Meter::create(['subscriber_id' => $this->subscriber('Shared image')->id, 'unique_code' => 'IMAGE-KEEP']);
        \Illuminate\Support\Facades\Storage::fake('public');
        $path = 'readings/shared-fixture.jpg';
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, 'synthetic');
        $removed = \App\Models\Reading::create([
            'meter_id' => $old->id, 'reading_date' => '2026-02-01', 'current_reading' => '20.00', 'image_path' => $path,
        ]);
        $retained = \App\Models\Reading::create([
            'meter_id' => $other->id, 'reading_date' => '2026-02-01', 'current_reading' => '15.00', 'image_path' => $path,
        ]);
        $sole = 'readings/sole-fixture.jpg';
        \Illuminate\Support\Facades\Storage::disk('public')->put($sole, 'synthetic sole owner');
        $exclusive = \App\Models\Reading::create([
            'meter_id' => $old->id, 'reading_date' => '2026-03-01', 'current_reading' => '25.00', 'image_path' => $sole,
        ]);
        $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(), 'reason' => 'حماية الصورة المشتركة',
            'new_meter' => ['unique_code' => 'IMAGE-NEW'],
        ])->assertCreated();
        $this->assertDatabaseMissing('readings', ['id' => $removed->id]);
        $this->assertDatabaseHas('readings', ['id' => $retained->id, 'image_path' => $path]);
        $this->assertDatabaseMissing('readings', ['id' => $exclusive->id]);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($sole);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($path);
    }

    public function test_round9_repeated_replacement_retains_previous_audit_chain(): void
    {
        $subscriber = $this->subscriber();
        $first = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'AUDIT-CHAIN-ONE']);
        $service = app(\App\Services\SingleMeterService::class);
        $second = $service->replace($first, ['unique_code' => 'AUDIT-CHAIN-TWO'],
            '2026-02-01', 'Synthetic first replacement')['current_meter'];
        $prior = \Illuminate\Support\Facades\DB::table('audit_events')->where('event', 'meter.replaced')
            ->where('entity_id', (string) $second->id)->first();
        $this->assertNotNull($prior);
        $pending = \App\Models\Reading::create([
            'meter_id' => $second->id, 'reading_date' => '2026-03-01', 'current_reading' => '20.00',
        ]);
        $third = $service->replace($second, ['unique_code' => 'AUDIT-CHAIN-THREE'],
            '2026-04-01', 'Synthetic second replacement')['current_meter'];
        $this->assertEquals($prior, \Illuminate\Support\Facades\DB::table('audit_events')->where('id', $prior->id)->first());
        $this->assertDatabaseMissing('readings', ['id' => $pending->id]);
        $this->assertDatabaseMissing('meters', ['id' => $second->id]);
        $audit = \App\Models\AuditEvent::where('event', 'meter.replaced')->where('entity_id', (string) $third->id)->firstOrFail();
        $this->assertSame($second->id, $audit->after['old_meter_id']);
        $this->assertSame(1, $audit->after['discarded_unbilled_readings']);
        $this->assertSame($third->id, $subscriber->fresh()->currentMeter->id);
    }

    public function test_round9_pending_only_receipt_review_blocks_all_ordinary_removal_paths(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'REVIEW-ONLY']);
        $collector = User::factory()->create(['role' => 'admin']);
        $session = \Illuminate\Support\Facades\DB::table('cash_sessions')->insertGetId([
            'user_id' => $collector->id, 'session_date' => '2026-02-01',
            'opening_amount' => 0, 'expected_amount' => 0, 'counted_amount' => 0, 'variance_amount' => 0,
            'state' => 'closed',
        ]);
        $review = \Illuminate\Support\Facades\DB::table('late_receipt_reviews')->insertGetId([
            'sync_uuid' => (string) \Illuminate\Support\Str::uuid(), 'cash_session_id' => $session,
            'collector_id' => $collector->id, 'subscriber_id' => $subscriber->id,
            'submitted_meter_id' => $old->id, 'amount' => '20.00',
            'payment_date' => '2026-02-01', 'payment_method' => 'cash', 'status' => 'pending',
        ]);
        $before = $this->meterCleanupSnapshot();
        $this->assertDatabaseCount('readings', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->deleteJson('/api/meters/'.$old->id)->assertConflict();
        $this->postJson('/api/meters/bulk-delete', ['ids' => [$old->id]])
            ->assertOk()->assertJsonPath('deleted_count', 0)->assertJsonPath('skipped_count', 1);
        $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => '2026-03-01', 'reason' => 'Synthetic pending review',
            'new_meter' => ['unique_code' => 'REVIEW-NEW'],
        ])->assertUnprocessable()->assertJsonValidationErrors('meter_id');
        foreach ([
            fn () => $old->fresh()->delete(),
            fn () => app(\App\Services\MeterDataPurger::class)->purge($old->id),
            fn () => \Illuminate\Support\Facades\DB::transaction(fn () =>
                app(\App\Services\FinancialMeterRetirementService::class)->retire($old, $subscriber)),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Pending review did not protect the meter.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('meter_id', $e->errors());
            }
            $this->assertEquals($before, $this->meterCleanupSnapshot());
        }
        $this->assertDatabaseHas('late_receipt_reviews', ['id' => $review, 'status' => 'pending']);
        $this->assertEquals($before, $this->meterCleanupSnapshot());
    }

    public function test_round9_general_cache_max_plus_one_rolls_back_retirement_without_losing_pending_usage(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'CACHE-OVERFLOW']);
        $ledger = app(\App\Services\TransactionService::class);
        // Ordinary supported adjustment postings: each partition fits its own field.
        $ledger->recordTransaction($subscriber, 'adjustment', '9999999999.99', 'Synthetic general limit');
        $ledger->recordTransaction($subscriber, 'adjustment', '0.01', 'Synthetic meter cent', meter: $old);
        $this->assertSame('9999999999.99', $subscriber->fresh()->balance);
        $this->assertSame('0.01', $old->fresh()->balance);
        $this->assertSame(1000000000000, $ledger->accountBalanceMinor($subscriber));
        \Illuminate\Support\Facades\Storage::fake('public');
        $image = 'readings/cache-overflow.jpg';
        \Illuminate\Support\Facades\Storage::disk('public')->put($image, 'synthetic');
        $pending = \App\Models\Reading::create([
            'meter_id' => $old->id, 'reading_date' => '2026-02-01', 'current_reading' => '20.00', 'image_path' => $image,
        ]);
        $before = $this->meterCleanupSnapshot();
        $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => '2026-03-01', 'reason' => 'Synthetic cache overflow',
            'new_meter' => ['unique_code' => 'CACHE-OVERFLOW-NEW'],
        ])->assertUnprocessable()->assertJsonValidationErrors('subscriber_balance');
        $this->assertEquals($before, $this->meterCleanupSnapshot());
        $this->assertSame(1000000000000, $ledger->accountBalanceMinor($subscriber));
        $this->assertDatabaseHas('readings', ['id' => $pending->id, 'meter_id' => $old->id]);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($image);
    }

    public function test_round9_cancelled_required_audit_rolls_back_financial_retirement_and_successor(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'AUDIT-CANCEL']);
        $this->measuredConsumption($old, 20);
        $invoice = app(\App\Services\InvoiceService::class)->generateInvoice($subscriber, $old);
        \Illuminate\Support\Facades\Storage::fake('public');
        $image = 'readings/audit-cancel.jpg';
        \Illuminate\Support\Facades\Storage::disk('public')->put($image, 'synthetic');
        $pending = \App\Models\Reading::create([
            'meter_id' => $old->id, 'reading_date' => '2026-03-01', 'current_reading' => '40.00', 'image_path' => $image,
        ]);
        $before = $this->meterCleanupSnapshot();
        $attempted = false;
        \App\Models\AuditEvent::creating(function (\App\Models\AuditEvent $audit) use ($old, $pending, $invoice, &$attempted) {
            if ($audit->event !== 'meter.replaced') {
                return;
            }
            $attempted = true;
            $this->assertDatabaseMissing('meters', ['id' => $old->id]);
            $this->assertDatabaseMissing('readings', ['id' => $pending->id]);
            $this->assertDatabaseHas('meters', ['unique_code' => 'AUDIT-CANCEL-NEW']);
            $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'retired_meter_id' => $old->id]);
            return false;
        });
        try {
            app(\App\Services\SingleMeterService::class)->replace($old, ['unique_code' => 'AUDIT-CANCEL-NEW'],
                '2026-04-01', 'Synthetic required audit cancellation');
            $this->fail('Replacement committed without its required audit.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Required meter replacement audit was not persisted.', $e->getMessage());
        }
        $this->assertTrue($attempted);
        $this->assertEquals($before, $this->meterCleanupSnapshot());
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($image);
    }

    private function financialRowsWithoutMeterProvenance(): array
    {
        $snapshot = [];
        foreach (['invoices', 'payments', 'transactions', 'invoice_items', 'invoice_tariff_lines',
            'payment_allocations', 'payment_allocation_revisions', 'payment_refunds',
            'payment_account_transfers', 'cash_sessions', 'treasury_postings',
            'bank_statement_matches', 'late_receipt_reviews'] as $table) {
            $snapshot[$table] = \Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()
                ->map(function ($row) use ($table) {
                    $row = (array) $row;
                    if (in_array($table, ['invoices', 'payments', 'transactions'], true)) {
                        unset($row['meter_id'], $row['retired_meter_id'], $row['retired_meter_code']);
                    }
                    return $row;
                })->all();
        }
        return $snapshot;
    }

    private function meterCleanupSnapshot(): array
    {
        $snapshot = [];
        foreach (['meters', 'subscribers', 'readings', 'invoices', 'invoice_items', 'invoice_tariff_lines',
            'payments', 'transactions', 'cash_sessions', 'audit_events', 'reading_faults', 'route_visits',
            'payment_allocations', 'payment_allocation_revisions', 'payment_refunds', 'payment_account_transfers',
            'treasury_postings', 'bank_statement_matches', 'late_receipt_reviews'] as $table) {
            $snapshot[$table] = \Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->all();
        }
        return $snapshot;
    }

    public function test_offline_batch_marks_physically_deleted_meter_non_retryable(): void
    {
        $subscriber = $this->subscriber();
        $old = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'OFFLINE-OLD',
        ]);
        $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'عداد جديد',
            'new_meter' => ['unique_code' => 'OFFLINE-NEW'],
        ])->assertCreated();

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

    public function test_first_reading_cannot_be_below_initial_meter_reading(): void
    {
        $subscriber = $this->subscriber();
        $meter = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'INITIAL-100',
            'initial_reading' => 100,
        ]);

        $this->assertSame(100.0, $meter->getLastReadingValue());
        $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'current_reading' => 90,
            'reading_date' => '2026-11-12',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_reading');

        $this->assertDatabaseCount('readings', 0);
    }

    public function test_new_reading_uses_observed_dates_even_if_client_sends_legacy_calendar_period(): void
    {
        $subscriber = $this->subscriber();
        $meter = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'OVERLAP-READING',
        ]);

        $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'current_reading' => 12,
            'reading_date' => '2026-10-31',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
        ])->assertCreated();

        $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'current_reading' => 20,
            'reading_date' => '2026-11-15',
            'period_start' => '2026-10-15',
            'period_end' => '2026-11-15',
        ])->assertCreated();

        $this->assertDatabaseCount('readings', 2);
        $latest = \App\Models\Reading::where('meter_id', $meter->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame('2026-10-31', $latest->period_start);
        $this->assertSame('2026-11-15', $latest->period_end);
        $this->assertSame('observation', $latest->period_basis);
    }

    public function test_filament_new_reading_uses_the_same_guard_as_api(): void
    {
        $subscriber = $this->subscriber();
        $meter = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'FILAMENT-READ-RETIRED',
            'removed_at' => '2026-10-01',
        ]);
        $class = new \ReflectionClass(\App\Filament\Resources\ReadingResource\Pages\CreateReading::class);
        $page = $class->newInstanceWithoutConstructor();
        $method = $class->getMethod('handleRecordCreation');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $method->invoke($page, [
            'meter_id' => $meter->id,
            'reading_date' => '2026-11-12',
            'current_reading' => 30,
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
        ]);
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
