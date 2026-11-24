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

    public function test_replacement_has_its_own_fractional_baseline_even_without_old_financial_history(): void
    {
        $old = $this->meter();
        $subscriberId = $old->subscriber_id;
        $result = app(\App\Services\SingleMeterService::class)->replace($old, [
            'unique_code' => 'PERIOD-OBS-REPLACEMENT',
            'initial_reading' => '100.25',
        ], '2026-02-01', 'Synthetic installation baseline test');
        $meter = $result['current_meter']->fresh();
        $this->assertSame('100.25', $meter->initial_reading);
        $this->assertSame('2026-02-01', $meter->installed_at->toDateString());
        $this->assertSame($subscriberId, $meter->subscriber_id);
        $id = $this->postJson('/api/readings', [
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12',
            'current_reading' => '135.75',
        ])->assertCreated()->json('reading.id');
        $reading = Reading::findOrFail($id);
        $this->assertSame('2026-02-01', $reading->period_start);
        $this->assertSame('35.50', $reading->consumption_units);
    }

    public function test_model_creates_only_actual_observations_without_client_periods(): void
    {
        $meter = $this->meter();
        $reading = Reading::create(['meter_id' => $meter->id,
            'reading_date' => '2026-02-12', 'current_reading' => '135.25']);
        $this->assertSame('observation', $reading->period_basis);
        $this->assertSame('2026-01-10', $reading->period_start);
        $this->assertSame('2026-02-12', $reading->period_end);
        $this->assertSame('35.25', $reading->consumption_units);

        foreach ([['period_basis' => 'legacy_calendar'], ['period_start' => '2026-02-01'],
            ['previous_reading' => 0], ['current_reading' => '136.123']] as $invalid) {
            try {
                Reading::create(array_replace(['meter_id' => $meter->id,
                    'reading_date' => '2026-03-14', 'current_reading' => '136.00'], $invalid));
                $this->fail('Invalid observation accepted.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertDatabaseCount('readings', 1);
    }

    public function test_database_rejects_inconsistent_observations(): void
    {
        $meter = $this->meter();
        $reading = Reading::create(['meter_id' => $meter->id,
            'reading_date' => '2026-02-12', 'current_reading' => '135.25']);
        foreach ([['consumption_units' => 1], ['period_end' => '2026-02-13'],
            ['actual_reading_date' => null], ['period_basis' => 'unknown']] as $invalid) {
            try {
                \Illuminate\Support\Facades\DB::table('readings')->where('id', $reading->id)->update($invalid);
                $this->fail('Database accepted an inconsistent observation.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('readings_observation_valid', $e->getMessage());
            }
        }
        $this->assertSame('35.25', $reading->fresh()->consumption_units);
    }

    public function test_fractional_consumption_survives_storage_edit_and_invoice_posting(): void
    {
        $meter = $this->meter();
        $meter->update(['initial_reading' => '100.25']);
        $tariff = \App\Models\Tariff::create(['name' => 'Decimal test tariff', 'unit_price' => '600.00',
            'start_date' => '2026-01-10', 'is_active' => true]);
        $id = $this->postJson('/api/readings', [
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12',
            'current_reading' => '135.75', 'consumption_units' => 9999,
        ])->assertCreated()->json('reading.id');
        $reading = Reading::findOrFail($id);
        $this->assertSame('35.50', $reading->consumption_units);
        $this->putJson('/api/readings/'.$id, ['current_reading' => '135.76'])->assertOk();
        $this->assertSame('35.51', $reading->fresh()->consumption_units);
        $this->putJson('/api/readings/'.$id, ['current_reading' => '135.765'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_reading');
        $this->putJson('/api/readings/'.$id, ['current_reading' => '135.75'])->assertOk();
        $invoice = app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter);
        $this->assertSame('21300.00', $invoice->consumption_fee);
        $this->assertSame('35.50', $reading->fresh()->consumption_units);
        $this->assertDatabaseHas('invoice_tariff_lines', [
            'invoice_id' => $invoice->id, 'reading_id' => $id, 'tariff_id' => $tariff->id,
            'units' => 35.50, 'amount' => 21300,
        ]);
        $this->assertSame(21300.0, $meter->subscriber->fresh()->getAccountDebt());
        $this->getJson('/api/admin/reports?from_date=2026-02-01&to_date=2026-02-28')
            ->assertOk()->assertJsonPath('summary.consumption_units', 35.5);
    }

    public function test_hundredths_are_not_lost_when_meter_values_are_close(): void
    {
        $meter = $this->meter();
        $meter->update(['initial_reading' => '100.29']);
        $id = $this->postJson('/api/readings', [
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12', 'current_reading' => '100.30',
        ])->assertCreated()->json('reading.id');
        $this->assertSame('0.01', Reading::findOrFail($id)->consumption_units);
        $this->postJson('/api/readings', [
            'meter_id' => $meter->id, 'reading_date' => '2026-03-14', 'current_reading' => '100.30',
        ])->assertCreated();
        $this->assertSame('0.00', Reading::latest('id')->firstOrFail()->consumption_units);
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
        $legacy = $this->insertLegacyReading([
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


    public function test_calendar_record_cannot_reenable_the_old_edit_algorithm(): void
    {
        $meter = $this->meter();
        $reading = $this->insertLegacyReading([
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12',
            'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
            'previous_reading' => 100, 'current_reading' => 135,
        ]);
        $this->putJson('/api/readings/'.$reading->id, [
            'reading_date' => '2026-02-15', 'current_reading' => 140,
        ])->assertUnprocessable()->assertJsonValidationErrors('period_start');
        try {
            $reading->update(['current_reading' => 140]);
            $this->fail('Calendar edit path was accepted.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('period_start', $e->errors());
        }
        $this->assertSame('135.00', $reading->fresh()->current_reading);
    }

    public function test_observation_cannot_change_its_preceding_value(): void
    {
        $meter = $this->meter();
        $id = $this->postJson('/api/readings', [
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12', 'current_reading' => 135,
        ])->assertCreated()->json('reading.id');
        $this->putJson('/api/readings/'.$id, ['previous_reading' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('previous_reading');
        $this->assertSame('100.00', Reading::findOrFail($id)->previous_reading);
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

    public function test_model_guard_protects_prior_observation_from_direct_edit_or_delete(): void
    {
        $meter = $this->meter();
        $firstId = $this->postJson('/api/readings', [
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12', 'current_reading' => 135,
        ])->assertCreated()->json('reading.id');
        $this->postJson('/api/readings', [
            'meter_id' => $meter->id, 'reading_date' => '2026-03-14', 'current_reading' => 148,
        ])->assertCreated();
        foreach ([
            fn () => Reading::findOrFail($firstId)->update(['current_reading' => 136]),
            fn () => Reading::findOrFail($firstId)->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Observation anchor was changed.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('reading_date', $e->errors());
            }
        }
        $this->assertSame('135.00', Reading::findOrFail($firstId)->current_reading);
        $this->assertDatabaseCount('readings', 2);
    }

    public function test_observation_audit_reports_legacy_exception_without_rewriting_it(): void
    {
        $meter = $this->meter();
        $legacy = $this->insertLegacyReading([
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12',
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
            'previous_reading' => 100, 'current_reading' => 135,
        ]);
        $before = \Illuminate\Support\Facades\DB::table('readings')->where('id', $legacy->id)->first();
        $this->artisan('water:audit-observation-intervals')
            ->expectsOutputToContain('LEGACY_EARLY_OBSERVATION reading #'.$legacy->id)->assertSuccessful();
        $this->assertEquals($before, \Illuminate\Support\Facades\DB::table('readings')->where('id', $legacy->id)->first());
        $id = $this->postJson('/api/readings', [
            'meter_id' => $meter->id, 'reading_date' => '2026-03-14', 'current_reading' => 148,
        ])->assertCreated()->json('reading.id');
        $this->artisan('water:audit-observation-intervals')->assertSuccessful();
        \Illuminate\Support\Facades\DB::table('readings')->where('id', $id)->update(['previous_reading' => 134, 'consumption_units' => 14]);
        $this->artisan('water:audit-observation-intervals')
            ->expectsOutputToContain('BROKEN_OBSERVATION_ANCHOR reading #'.$id)->assertFailed();
        $this->assertEquals(134, Reading::findOrFail($id)->previous_reading);
    }

    public function test_reference_dates_and_initial_meter_value_are_frozen_after_reading(): void
    {
        $meter = $this->meter();
        $meter->subscriber->update(['subscription_start_date' => '2026-01-01']);
        $this->postJson('/api/readings', [
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12', 'current_reading' => 135,
        ])->assertCreated();
        foreach ([
            fn () => $meter->fresh()->update(['installed_at' => '2026-01-11']),
            fn () => $meter->fresh()->update(['initial_reading' => 105]),
            fn () => $meter->subscriber->fresh()->update(['subscription_start_date' => '2026-01-02']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A historical baseline was changed.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame('2026-01-10', $meter->fresh()->installed_at->toDateString());
        $this->assertSame('100.00', $meter->fresh()->initial_reading);
        $this->assertSame('2026-01-01', $meter->subscriber->fresh()->subscription_start_date->toDateString());
    }

    public function test_stale_reading_cannot_overwrite_concurrent_change(): void
    {
        $meter = $this->meter();
        $id = $this->postJson('/api/readings', [
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12', 'current_reading' => 135,
        ])->assertCreated()->json('reading.id');
        $stale = Reading::findOrFail($id);
        Reading::findOrFail($id)->update(['current_reading' => 140]);
        try {
            $stale->update(['current_reading' => 141]);
            $this->fail('A stale update was accepted.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('reading_date', $e->errors());
        }
        $this->assertSame('140.00', Reading::findOrFail($id)->current_reading);
    }

    public function test_offline_retry_rejects_changed_actual_observation_date(): void
    {
        $meter = $this->meter();
        $payload = ['meter_id' => $meter->id, 'reading_date' => '2026-02-13',
            'actual_reading_date' => '2026-02-12', 'current_reading' => 135,
            'sync_uuid' => (string) \Illuminate\Support\Str::uuid()];
        $this->postJson('/api/readings', $payload)->assertCreated();
        $this->postJson('/api/readings', $payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->postJson('/api/readings', array_replace($payload, ['actual_reading_date' => '2026-02-11']))
            ->assertConflict();
        $this->assertDatabaseCount('readings', 1);
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
