<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HistoricalTariffTest extends TestCase
{
    use RefreshDatabase;

    private function meter(): Meter
    {
        $subscriber = Subscriber::create(['name' => 'Synthetic historical tariff',
            'square_id' => Square::create(['name' => 'Tariff test'])->id,
            'status' => 'active']);
        return Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'TARIFF-1']);
    }

    private function tariff(string $price, ?string $start, ?string $end): Tariff
    {
        return Tariff::create(['name' => 'Historical tariff '.$price, 'unit_price' => $price,
            'is_active' => true, 'start_date' => $start, 'end_date' => $end]);
    }

    private function read(Meter $meter, string $start, string $end, int $previous, int $current): Reading
    {
        return $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-04-10',
            'period_start' => $start, 'period_end' => $end,
            'previous_reading' => $previous, 'current_reading' => $current]);
    }

    public function test_each_measured_period_uses_its_own_tariff_not_invoice_date(): void
    {
        $meter = $this->meter();
        $old = $this->tariff('600.25', '2026-01-01', '2026-01-31');
        $new = $this->tariff('800.50', '2026-02-01', '2026-02-28');
        $this->tariff('1000.00', '2026-03-01', null);
        $first = $this->read($meter, '2026-01-01', '2026-02-01', 0, 10);
        $second = $this->read($meter, '2026-02-01', '2026-03-01', 10, 15);
        $invoice = app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter);
        $this->assertSame('10005.00', $invoice->total_amount);
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $invoice->id,
            'reading_id' => $first->id, 'tariff_id' => $old->id, 'unit_price' => 600.25, 'amount' => 6002.50]);
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $invoice->id,
            'reading_id' => $second->id, 'tariff_id' => $new->id, 'unit_price' => 800.50, 'amount' => 4002.50]);
        $this->assertSame(10005.0, $meter->subscriber->getAccountDebt());
        $this->assertDatabaseCount('invoice_tariff_lines', 2);
    }

    public function test_missing_tariff_does_not_fall_back_to_an_arbitrary_price(): void
    {
        $meter = $this->meter();
        $reading = $this->read($meter, '2026-01-01', '2026-02-01', 0, 10);
        $this->assertRejected($meter, $reading);
    }

    public function test_crossing_a_price_change_without_boundary_reading_is_rejected(): void
    {
        $meter = $this->meter();
        $this->tariff('600.00', '2026-01-01', '2026-01-31');
        $this->tariff('800.00', '2026-02-01', null);
        $reading = $this->read($meter, '2026-01-01', '2026-03-01', 0, 15);
        $this->assertRejected($meter, $reading);
    }

    public function test_partial_coverage_and_overlapping_prices_are_rejected(): void
    {
        $meter = $this->meter();
        $this->tariff('600.00', '2026-01-15', '2026-01-31');
        $reading = $this->read($meter, '2026-01-01', '2026-02-01', 0, 10);
        $this->assertRejected($meter, $reading);
        // Simulate overlapping legacy/imported data that predates the guard.
        DB::table('tariffs')->insert([
            'name' => 'Legacy overlapping tariff', 'unit_price' => 700,
            'is_active' => true, 'start_date' => '2026-01-01',
        ]);
        $this->assertRejected($meter, $reading);
    }

    public function test_used_tariff_cannot_be_repriced_deactivated_deleted_or_shortened_past_evidence(): void
    {
        $meter = $this->meter();
        $tariff = $this->tariff('600.00', '2026-01-01', null);
        $this->read($meter, '2026-01-01', '2026-02-01', 0, 10);
        $invoice = app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter);

        foreach ([
            fn () => $tariff->fresh()->update(['unit_price' => '800.00']),
            fn () => $tariff->fresh()->update(['is_active' => false]),
            fn () => $tariff->fresh()->update(['start_date' => '2026-01-02']),
            fn () => $tariff->fresh()->update(['end_date' => '2026-01-30']),
            fn () => $tariff->fresh()->delete(),
            fn () => $tariff->fresh()->forceDelete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A historically referenced tariff was modified.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        // A new tariff can start on the next reading boundary without
        // invalidating the already recorded pricing evidence.
        $tariff->fresh()->update(['end_date' => '2026-01-31']);
        $this->assertSame('600.00', $tariff->fresh()->unit_price);
        $this->assertTrue($tariff->fresh()->is_active);
        $this->assertSame('2026-01-31', $tariff->fresh()->end_date->toDateString());
        $this->assertDatabaseHas('invoice_tariff_lines', [
            'invoice_id' => $invoice->id,
            'tariff_id' => $tariff->id,
            'unit_price' => 600,
            'amount' => 6000,
        ]);
    }

    public function test_catalog_rejects_overlap_on_create_update_and_restore(): void
    {
        $old = $this->tariff('600.00', '2026-01-01', '2026-01-31');
        $next = $this->tariff('800.00', '2026-02-01', null);
        $draft = Tariff::create(['name' => 'Draft', 'unit_price' => 900,
            'start_date' => '2026-01-15', 'end_date' => '2026-02-15', 'is_active' => false]);
        foreach ([
            fn () => $this->tariff('500.00', null, '2026-01-01'),
            fn () => $draft->fresh()->update(['is_active' => true]),
            fn () => $old->fresh()->update(['end_date' => '2026-02-01']),
            fn () => $next->fresh()->update(['end_date' => '2026-01-31']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Invalid tariff schedule accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $next->delete();
        $replacement = $this->tariff('950.00', '2026-02-01', null);
        try {
            Tariff::withTrashed()->findOrFail($next->id)->restore();
            $this->fail('Overlapping deleted tariff restored.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('is_active', $exception->errors());
        }
        $this->assertTrue(Tariff::withTrashed()->findOrFail($next->id)->trashed());
        $this->assertTrue($replacement->fresh()->is_active);
        $this->assertFalse($draft->fresh()->is_active);
        $this->assertSame('2026-01-31', $old->fresh()->end_date->toDateString());
    }

    public function test_stale_tariff_instance_cannot_overwrite_schedule(): void
    {
        $tariff = $this->tariff('600.00', '2026-01-01', null);
        $stale = $tariff->fresh();
        $tariff->fresh()->update(['end_date' => '2026-01-31']);
        try {
            $stale->update(['unit_price' => 800]);
            $this->fail('Stale tariff accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tariff', $exception->errors());
        }
        $this->assertSame('600.00', $tariff->fresh()->unit_price);
        $this->assertSame('2026-01-31', $tariff->fresh()->end_date->toDateString());
    }

    public function test_manual_api_issuance_ignores_client_amount_and_period_and_cannot_bill_twice(): void
    {
        $meter = $this->meter();
        $tariff = $this->tariff('600.25', '2026-01-01', '2026-01-31');
        $reading = $this->read($meter, '2026-01-01', '2026-02-01', 0, 10);
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $payload = [
            'subscriber_id' => $meter->subscriber_id,
            'meter_id' => $meter->id,
            'consumption_fee' => 1,
            'total_amount' => 1,
            'previous_balance' => 999999,
            'credit_applied' => 999999,
            'bill_number' => 'CLIENT-OVERRIDE',
            'bill_date' => '2020-01-01',
            'billing_period_start' => '2020-01-01',
            'billing_period_end' => '2020-02-01',
        ];
        $this->actingAs($admin, 'sanctum')->postJson('/api/invoices', $payload)->assertCreated()
            ->assertJsonPath('invoice.tariff_lines.0.unit_price', '600.25');
        $invoice = Invoice::sole();
        $this->assertSame('6002.50', $invoice->total_amount);
        $this->assertSame('2026-01-01', $invoice->billing_period_start->toDateString());
        $this->assertSame('2026-02-01', $invoice->billing_period_end->toDateString());
        $this->assertSame('0.00', $invoice->previous_balance);
        $this->assertNotSame('CLIENT-OVERRIDE', $invoice->bill_number);
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $invoice->id,
            'reading_id' => $reading->id, 'tariff_id' => $tariff->id, 'amount' => 6002.50]);
        $this->assertSame($invoice->id, $reading->fresh()->invoice_id);
        $this->getJson('/api/invoices')->assertOk()->assertJsonPath('data.0.tariff_lines.0.unit_price', '600.25');
        $this->getJson('/api/invoices/'.$invoice->id)->assertOk()->assertJsonPath('invoice.tariff_lines.0.amount', '6002.50');
        $this->postJson('/api/invoices', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_current_price_is_unknown_without_unique_effective_approval(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-04-30 18:00:00'));
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum');
        $this->assertNull(Tariff::getActivePrice());
        $this->getJson('/api/tariffs')->assertOk()->assertJsonPath('active_price', null);
        $this->tariff('800.00', '2026-05-01', null);
        $this->assertNull(Tariff::getActivePrice());

        $this->tariff('0.00', '2026-04-01', '2026-04-30');
        $this->assertSame(0.0, Tariff::getActivePrice());
        // Legacy imports may bypass the model guard; never choose a winner.
        DB::table('tariffs')->insert(['name' => 'Legacy overlap', 'unit_price' => 900,
            'is_active' => true, 'start_date' => '2026-04-01', 'end_date' => '2026-04-30']);
        $this->assertNull(Tariff::getActivePrice());
        $this->getJson('/api/tariffs')->assertOk()->assertJsonPath('active_price', null);
        $this->travelBack();
    }

    public function test_bulk_catalog_changes_roll_back_when_any_selected_tariff_is_protected(): void
    {
        $draft = Tariff::create(['name' => 'Draft', 'unit_price' => 900, 'is_active' => false]);
        $used = $this->tariff('600.00', '2026-01-01', null);
        $meter = $this->meter();
        $this->read($meter, '2026-01-01', '2026-02-01', 0, 10);
        app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter);
        $service = app(\App\Services\TariffBulkService::class);

        foreach (['delete', 'forceDelete'] as $operation) {
            try {
                $service->apply([$draft->id, $used->id], $operation);
                $this->fail('Protected tariff bulk action was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('tariff', $exception->errors());
            }
            $this->assertDatabaseHas('tariffs', ['id' => $draft->id, 'deleted_at' => null]);
            $this->assertDatabaseHas('tariffs', ['id' => $used->id, 'deleted_at' => null]);
        }
        $this->assertSame(1, $service->apply([$draft->id], 'delete'));
        $this->assertSame(1, $service->apply([$draft->id], 'restore'));
    }

    public function test_bulk_restore_is_atomic_when_a_restored_tariff_overlaps(): void
    {
        $draft = Tariff::create(['name' => 'Draft', 'unit_price' => 900, 'is_active' => false]);
        $old = $this->tariff('600.00', '2026-01-01', null);
        $draft->delete();
        $old->delete();
        $this->tariff('800.00', '2026-01-01', null);
        try {
            app(\App\Services\TariffBulkService::class)->apply([$draft->id, $old->id], 'restore');
            $this->fail('Conflicting bulk restore was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('is_active', $exception->errors());
        }
        $this->assertTrue(Tariff::withTrashed()->findOrFail($draft->id)->trashed());
        $this->assertTrue(Tariff::withTrashed()->findOrFail($old->id)->trashed());
    }

    public function test_read_only_tariff_audit_detects_unpriceable_readings_and_legacy_overlap(): void
    {
        $meter = $this->meter();
        $reading = $this->read($meter, '2026-01-01', '2026-02-01', 0, 10);
        $this->artisan('water:audit-historical-tariffs')->expectsOutputToContain('UNPRICEABLE reading #'.$reading->id)->assertFailed();
        $this->assertDatabaseCount('invoices', 0);
        $this->tariff('600.00', '2026-01-01', '2026-01-31');
        $this->artisan('water:audit-historical-tariffs')->assertSuccessful();
        DB::table('tariffs')->insert(['name' => 'Legacy overlap', 'unit_price' => 800,
            'is_active' => true, 'start_date' => '2026-01-10', 'end_date' => '2026-01-20']);
        $before = DB::table('tariffs')->orderBy('id')->get()->toJson();
        $this->artisan('water:audit-historical-tariffs')->expectsOutputToContain('OVERLAP')->assertFailed();
        $this->assertSame($before, DB::table('tariffs')->orderBy('id')->get()->toJson());
        $this->assertFalse($reading->fresh()->is_invoiced);
    }

    public function test_pdf_uses_mixed_tariff_label_instead_of_invented_average_price(): void
    {
        $meter = $this->meter();
        $this->tariff('600.00', '2026-01-01', '2026-01-31');
        $this->tariff('800.00', '2026-02-01', null);
        $this->read($meter, '2026-01-01', '2026-02-01', 0, 10);
        $this->read($meter, '2026-02-01', '2026-03-01', 10, 15);
        $invoice = app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter);
        $html = view('pdf.invoice', ['invoice' => $invoice->fresh()])->render();
        $this->assertStringContainsString('تعرفات متعددة', $html);
        $this->assertStringNotContainsString('666.67', $html);
    }

    private function assertRejected(Meter $meter, Reading $reading): void
    {
        try {
            app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter);
            $this->fail('Invalid tariff period was billed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tariff', $exception->errors());
        }
        $this->assertFalse($reading->fresh()->is_invoiced);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('invoice_tariff_lines', 0);
    }
}
