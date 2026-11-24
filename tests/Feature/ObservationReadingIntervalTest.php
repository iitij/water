<?php

namespace Tests\Feature;

use App\Models\Meter;
use App\Models\Reading;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\ReadingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservationReadingIntervalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
    }

    private function meter(?string $installedAt = '2026-01-10'): Meter
    {
        $square = Square::create(['name' => 'مربع اختبار فترات الزيارات']);
        $subscriber = Subscriber::create([
            'name' => 'مشترك اختبار الفترات',
            'square_id' => $square->id,
            'status' => 'active',
        ]);
        return Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'PERIOD-OBS-1',
            'initial_reading' => 100,
            'installed_at' => $installedAt,
        ]);
    }

    public function test_first_interval_runs_from_installation_to_actual_reading_date(): void
    {
        $meter = $this->meter();
        $response = $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'reading_date' => '2026-02-12',
            'current_reading' => 135,
            'period_start' => '2026-02-01', // legacy forecast ignored
            'period_end' => '2026-02-28',
        ])->assertCreated();

        $reading = Reading::findOrFail($response->json('reading.id'));
        $this->assertSame('2026-01-10', $reading->period_start);
        $this->assertSame('2026-02-12', $reading->period_end);
        $this->assertSame('observation', $reading->period_basis);
        $this->assertEqualsWithDelta(35, (float) $reading->consumption_units, 0.01);

        \App\Models\Tariff::create(['name' => 'Synthetic observation tariff', 'unit_price' => 600,
            'start_date' => '2026-01-10', 'is_active' => true]);
        $invoice = app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter);
        $this->assertSame('2026-01-10', $invoice->billing_period_start->toDateString());
        $this->assertSame('2026-02-12', $invoice->billing_period_end->toDateString());
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $invoice->id,
            'reading_id' => $reading->id, 'period_start' => '2026-01-10', 'period_end' => '2026-02-12',
            'units' => 35, 'unit_price' => 600, 'amount' => 21000]);
    }

    public function test_second_interval_begins_at_previous_observation_without_adding_a_day(): void
    {
        $meter = $this->meter();
        $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'reading_date' => '2026-02-12',
            'current_reading' => 135,
        ])->assertCreated();

        $period = app(ReadingService::class)
            ->calculateReadingPeriod($meter->id, '2026-03-14');

        $this->assertSame('2026-02-12', $period['period_start']);
        $this->assertSame('2026-03-14', $period['period_end']);

        $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'reading_date' => '2026-03-14',
            'current_reading' => 148,
        ])->assertCreated();

        $last = Reading::query()->where('meter_id', $meter->id)
            ->orderByDesc('id')->firstOrFail();
        $this->assertSame('2026-02-12', $last->period_start);
        $this->assertSame('2026-03-14', $last->period_end);
        $this->assertEqualsWithDelta(13, (float) $last->consumption_units, 0.01);
    }

    public function test_missing_installation_or_service_start_cannot_invent_a_first_period(): void
    {
        $meter = $this->meter(null);
        $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'reading_date' => '2026-02-12',
            'current_reading' => 135,
        ])->assertUnprocessable()->assertJsonValidationErrors('period_start');
        $this->assertDatabaseCount('readings', 0);
    }

    public function test_a_new_observation_cannot_go_backwards_or_use_the_same_day(): void
    {
        $meter = $this->meter();
        $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'reading_date' => '2026-02-12',
            'current_reading' => 135,
        ])->assertCreated();

        $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'reading_date' => '2026-02-12',
            'current_reading' => 140,
        ])->assertUnprocessable()->assertJsonValidationErrors('reading_date');
        $this->assertDatabaseCount('readings', 1);
    }

    public function test_existing_calendar_readings_remain_unchanged_and_next_real_date_is_used(): void
    {
        $meter = $this->meter();
        $legacy = Reading::create([
            'meter_id' => $meter->id,
            'reading_date' => '2026-02-12',
            'actual_reading_date' => '2026-02-12',
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'previous_reading' => 100,
            'current_reading' => 135,
        ]);

        $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'reading_date' => '2026-03-14',
            'current_reading' => 148,
        ])->assertCreated();

        $this->assertSame('legacy_calendar', $legacy->fresh()->period_basis);
        $this->assertSame('2026-02-28', $legacy->fresh()->period_end);
        $latest = Reading::query()->where('meter_id', $meter->id)
            ->orderByDesc('id')->firstOrFail();
        $this->assertSame('2026-02-12', $latest->period_start);
        $this->assertSame('2026-03-14', $latest->period_end);
    }

    public function test_legacy_calendar_hints_do_not_break_exact_idempotent_retries(): void
    {
        $meter = $this->meter();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $payload = [
            'meter_id' => $meter->id,
            'reading_date' => '2026-02-12',
            'current_reading' => 135,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'sync_uuid' => $uuid,
        ];
        $saved = $this->postJson('/api/readings', $payload)->assertCreated();
        $this->postJson('/api/readings', $payload)
            ->assertOk()->assertJsonPath('duplicate', true);
        $reading = Reading::findOrFail($saved->json('reading.id'));
        $this->assertSame('2026-01-10', $reading->period_start);
        $this->assertSame('2026-02-12', $reading->period_end);
        $this->assertSame('2026-02-01', $reading->submitted_period_start);
        $this->assertSame('2026-02-28', $reading->submitted_period_end);
        $this->assertDatabaseCount('readings', 1);
    }


    public function test_updating_latest_observation_recalculates_period_end(): void
    {
        $meter = $this->meter();
        $readingId = $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'reading_date' => '2026-02-12',
            'current_reading' => 135,
        ])->assertCreated()->json('reading.id');

        $this->putJson('/api/readings/'.$readingId, [
            'reading_date' => '2026-02-15',
        ])->assertOk();

        $reading = Reading::findOrFail($readingId);
        $this->assertSame('2026-01-10', $reading->period_start);
        $this->assertSame('2026-02-15', $reading->period_end);
        $this->assertSame('2026-02-15', $reading->actual_reading_date->toDateString());
    }

    public function test_prior_observation_cannot_be_modified_after_a_successor_exists(): void
    {
        $meter = $this->meter();
        $first = $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'reading_date' => '2026-02-12',
            'current_reading' => 135,
        ])->assertCreated()->json('reading.id');
        $this->postJson('/api/readings', [
            'meter_id' => $meter->id,
            'reading_date' => '2026-03-14',
            'current_reading' => 148,
        ])->assertCreated();

        $this->putJson('/api/readings/'.$first, [
            'reading_date' => '2026-02-15',
        ])->assertUnprocessable()->assertJsonValidationErrors('reading_date');

        $this->assertSame('2026-02-12', Reading::findOrFail($first)->period_end);
    }

}
