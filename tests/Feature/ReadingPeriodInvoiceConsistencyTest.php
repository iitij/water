<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\ReadingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadingPeriodInvoiceConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Tariff::create(['name' => 'Synthetic baseline tariff', 'unit_price' => 600, 'is_active' => true]);
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
    }

    private function meter(string $code = 'PERIOD-100'): Meter
    {
        $square = Square::create(['name' => 'مربع فترات القراءة']);
        $subscriber = Subscriber::create([
            'name' => 'مشترك فترات القراءة',
            'square_id' => $square->id,
            'status' => 'active',
            'subscription_start_date' => '2026-01-01',
        ]);

        return Meter::create([
            'unique_code' => $code,
            'subscriber_id' => $subscriber->id,
            'initial_reading' => 0,
        ]);
    }

    private function reading(
        Meter $meter,
        string $start,
        string $end,
        string $entered,
        float $current,
        float $previous = 0,
        ?int $invoiceId = null
    ): Reading {
        return Reading::create([
            'meter_id' => $meter->id,
            'reading_date' => $entered,
            'actual_reading_date' => $entered,
            'period_start' => $start,
            'period_end' => $end,
            'previous_reading' => $previous,
            'current_reading' => $current,
            'invoice_id' => $invoiceId,
            'is_invoiced' => $invoiceId !== null,
        ]);
    }

    public function test_monthly_configured_day_31_is_clamped_to_short_february(): void
    {
        $meter = $this->meter();
        $meter->subscriber->update(['subscription_start_date' => '2025-02-01']);
        Setting::set('reading_period', 'monthly');
        Setting::set('reading_day', '31');

        $period = app(ReadingService::class)->calculateReadingPeriod($meter->id);

        $this->assertSame('2025-02-01', $period['period_start']);
        $this->assertSame('2025-02-28', $period['period_end']);
    }

    public function test_next_period_uses_latest_period_end_not_latest_inserted_id(): void
    {
        $meter = $this->meter();
        $this->reading($meter, '2026-02-01', '2026-02-28', '2026-03-12', 20);
        $this->reading($meter, '2026-01-01', '2026-01-31', '2026-03-20', 10);
        Setting::set('reading_period', 'monthly');
        Setting::set('reading_day', 'last_day');

        $period = app(ReadingService::class)->calculateReadingPeriod($meter->id);

        $this->assertSame('2026-03-01', $period['period_start']);
        $this->assertSame('2026-03-31', $period['period_end']);
        $this->assertSame(20.0, $meter->fresh()->getLastReadingValue());
    }

    public function test_generated_invoice_period_follows_reading_periods_not_entry_dates(): void
    {
        $meter = $this->meter();
        $january = $this->reading($meter, '2026-01-01', '2026-01-31', '2026-03-20', 10);
        $february = $this->reading($meter, '2026-02-01', '2026-02-28', '2026-03-10', 25, 10);

        $invoice = app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter);
        $invoice->refresh();

        $this->assertSame('2026-01-01', $invoice->billing_period_start->toDateString());
        $this->assertSame('2026-02-28', $invoice->billing_period_end->toDateString());
        $this->assertSame([$january->id, $february->id], $invoice->readings()->orderBy('period_start')->pluck('id')->all());
        $this->assertSame(25, $invoice->readings->sum('consumption_units'));
        $this->assertEqualsWithDelta(15000, (float) $invoice->consumption_fee, 0.01);
    }

    public function test_api_preserves_late_actual_entry_date_separate_from_consumption_period(): void
    {
        $meter = $this->meter();
        $response = $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'reading_date' => '2026-03-05',
            'current_reading' => 20,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
        ])->assertCreated();

        $id = $response->json('reading.id');
        $reading = Reading::findOrFail($id);
        $this->assertSame('2026-03-05', $reading->actual_reading_date->toDateString());
        $this->assertSame('2026-02-01', $reading->period_start);
        $this->assertSame('2026-02-28', $reading->period_end);
    }

    public function test_updating_a_reading_cannot_overlap_another_consumption_period(): void
    {
        $meter = $this->meter();
        $this->reading($meter, '2026-01-01', '2026-01-31', '2026-02-02', 10);
        $february = $this->reading($meter, '2026-02-01', '2026-02-28', '2026-03-05', 20, 10);

        $this->putJson('/api/readings/'.$february->id, [
            'period_start' => '2026-01-15',
        ])->assertUnprocessable()->assertJsonValidationErrors('period_start');

        $this->assertSame('2026-02-01', $february->fresh()->period_start);
    }

    public function test_pdf_does_not_infer_another_invoices_reading_by_entry_date(): void
    {
        $meter = $this->meter();
        $manual = Invoice::create([
            'subscriber_id' => $meter->subscriber_id,
            'meter_id' => $meter->id,
            'invoice_type' => 'meter',
            'bill_number' => 'INV-EMPTY-READINGS',
            'bill_date' => '2026-04-03',
            'billing_period_start' => '2026-03-01',
            'billing_period_end' => '2026-03-31',
            'consumption_fee' => 1200,
            'total_amount' => 1200,
            'status' => 'issued',
        ]);
        $this->reading($meter, '2026-02-01', '2026-02-28', '2026-03-15', 9876);

        $html = view('pdf.invoice', ['invoice' => $manual->fresh()])->render();

        $this->assertStringContainsString('01/03/2026', $html);
        $this->assertStringContainsString('31/03/2026', $html);
        $this->assertStringNotContainsString('9,876.00', $html);
        $this->assertStringContainsString('—', $html);
    }

    public function test_recalculate_does_not_borrow_unrelated_meter_readings(): void
    {
        $meter = $this->meter();
        $invoice = Invoice::create([
            'subscriber_id' => $meter->subscriber_id,
            'meter_id' => $meter->id,
            'invoice_type' => 'meter',
            'bill_number' => 'INV-RECALC-NO-READINGS',
            'bill_date' => '2026-04-03',
            'billing_period_start' => '2026-03-01',
            'billing_period_end' => '2026-03-31',
            'consumption_fee' => 1200,
            'total_amount' => 1200,
            'status' => 'draft',
        ]);
        $this->reading($meter, '2026-02-01', '2026-02-28', '2026-03-15', 50);

        $this->postJson('/api/invoices/'.$invoice->id.'/recalculate')->assertOk();
        $this->assertEqualsWithDelta(1200, (float) $invoice->fresh()->consumption_fee, 0.01);
    }

    public function test_meter_period_endpoint_returns_reading_from_latest_consumption_period(): void
    {
        $meter = $this->meter();
        $this->reading($meter, '2026-02-01', '2026-02-28', '2026-03-12', 20);
        $this->reading($meter, '2026-01-01', '2026-01-31', '2026-03-20', 10);
        Setting::set('reading_period', 'monthly');
        Setting::set('reading_day', 'last_day');

        $this->getJson('/api/readings/calculate-dates?meter_id='.$meter->id)
            ->assertOk()
            ->assertJsonPath('period_start', '2026-03-01')
            ->assertJsonPath('period_end', '2026-03-31')
            ->assertJsonPath('last_reading', 20);
    }

    public function test_invoice_edit_cannot_desynchronize_its_reading_period(): void
    {
        $meter = $this->meter();
        $reading = $this->reading($meter, '2026-02-01', '2026-02-28', '2026-03-12', 10);
        $invoice = app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter);

        $this->putJson('/api/invoices/'.$invoice->id, [
            'billing_period_start' => '2026-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('billing_period_start');

        $this->assertSame('2026-02-01', $invoice->fresh()->billing_period_start->toDateString());
        $this->assertSame($invoice->id, $reading->fresh()->invoice_id);
    }

    public function test_recalculate_restores_period_from_the_invoices_own_readings(): void
    {
        $meter = $this->meter();
        $invoice = Invoice::create([
            'subscriber_id' => $meter->subscriber_id,
            'meter_id' => $meter->id,
            'invoice_type' => 'meter',
            'bill_number' => 'INV-RECALC-DATES',
            'bill_date' => '2026-04-03',
            'billing_period_start' => '2026-01-01',
            'billing_period_end' => '2026-01-31',
            'consumption_fee' => 6000,
            'total_amount' => 6000,
            'status' => 'draft',
        ]);
        $this->reading($meter, '2026-02-01', '2026-02-28', '2026-03-12', 10, 0, $invoice->id);

        $this->postJson('/api/invoices/'.$invoice->id.'/recalculate')->assertOk();

        $this->assertSame('2026-02-01', $invoice->fresh()->billing_period_start->toDateString());
        $this->assertSame('2026-02-28', $invoice->fresh()->billing_period_end->toDateString());
    }

    public function test_pdf_never_invents_period_dates_for_an_invoice_without_stored_period(): void
    {
        $meter = $this->meter();
        $invoice = Invoice::create([
            'subscriber_id' => $meter->subscriber_id,
            'meter_id' => $meter->id,
            'bill_number' => 'INV-EMPTY-PERIOD',
            'bill_date' => '2026-04-03',
            'consumption_fee' => 0,
            'total_amount' => 0,
            'status' => 'issued',
        ]);

        $html = view('pdf.invoice', ['invoice' => $invoice->fresh()])->render();

        $this->assertStringNotContainsString('01/03/2026', $html);
        $this->assertStringNotContainsString('31/03/2026', $html);
        $this->assertStringContainsString('—', $html);
    }


    public function test_editing_an_entered_date_updates_the_matching_actual_date(): void
    {
        $meter = $this->meter();
        $reading = $this->reading($meter, '2026-02-01', '2026-02-28', '2026-03-05', 10);

        $this->putJson('/api/readings/'.$reading->id, [
            'reading_date' => '2026-03-09',
        ])->assertOk();

        $this->assertSame('2026-03-09', $reading->fresh()->actual_reading_date->toDateString());
        $this->assertSame('2026-02-01', $reading->fresh()->period_start);
        $this->assertSame('2026-02-28', $reading->fresh()->period_end);
    }

    public function test_editing_entered_date_preserves_a_distinct_actual_date(): void
    {
        $meter = $this->meter();
        $reading = $this->reading($meter, '2026-02-01', '2026-02-28', '2026-03-05', 10);
        $reading->update(['actual_reading_date' => '2026-03-07']);

        $this->putJson('/api/readings/'.$reading->id, [
            'reading_date' => '2026-03-09',
        ])->assertOk();

        $this->assertSame('2026-03-07', $reading->fresh()->actual_reading_date->toDateString());
    }


    public function test_idempotent_retry_cannot_silently_change_its_saved_period(): void
    {
        $meter = $this->meter();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $original = [
            'meter_id' => $meter->id,
            'reading_date' => '2026-03-05',
            'current_reading' => 20,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'sync_uuid' => $uuid,
        ];
        $id = $this->postJson('/api/readings', $original)->assertCreated()->json('reading.id');

        $this->postJson('/api/readings', [
            ...$original,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
        ])->assertStatus(409);

        $this->assertDatabaseHas('readings', [
            'id' => $id,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
        ]);
        $this->assertDatabaseCount('readings', 1);
    }

}
