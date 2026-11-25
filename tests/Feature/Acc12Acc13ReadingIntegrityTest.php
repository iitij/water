<?php

namespace Tests\Feature;

use App\Models\Meter;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\ReadingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class Acc12Acc13ReadingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function meter(string $initial = '100.00'): Meter
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        Setting::set('auto_issue_invoice_on_reading', '0');
        $square = Square::create(['name' => 'Round 4A synthetic square']);
        $subscriber = Subscriber::create([
            'name' => 'Round 4A synthetic subscriber', 'square_id' => $square->id, 'status' => 'active',
        ]);

        return Meter::create([
            'subscriber_id' => $subscriber->id, 'unique_code' => 'C-ROUND4A',
            'installed_at' => '2026-01-10', 'initial_reading' => $initial,
        ]);
    }

    private function expectInvalid(callable $write, string $field): void
    {
        try {
            $write();
            $this->fail('Invalid reading was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_raw_previous_precision_is_not_rounded_before_validation(): void
    {
        $meter = $this->meter();
        foreach (['100.001', '100.000', '100.004'] as $invalid) {
            $this->expectInvalid(fn () => Reading::create([
                'meter_id' => $meter->id, 'reading_date' => '2026-02-12',
                'previous_reading' => $invalid, 'current_reading' => '135.25',
            ]), 'previous_reading');
        }
        $this->assertDatabaseCount('readings', 0);
    }

    public function test_edit_rejects_precision_even_when_decimal_cast_would_be_unchanged(): void
    {
        $meter = $this->meter();
        $reading = Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12', 'current_reading' => '135.25',
        ]);
        foreach (['135.251', '135.250', '-1.00', '99.99'] as $invalid) {
            $this->expectInvalid(fn () => $reading->fresh()->update(['current_reading' => $invalid]),
                $invalid === '99.99' ? 'reading_date' : 'current_reading');
        }
        $this->assertSame('135.25', $reading->fresh()->current_reading);
        $this->assertSame('35.25', $reading->fresh()->consumption_units);
    }

    public function test_maximum_supported_quantity_and_zero_usage_preserve_hundredths(): void
    {
        $meter = $this->meter('9999999999.98');
        $first = Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12', 'current_reading' => '9999999999.99',
        ]);
        $second = Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-03-12', 'current_reading' => '9999999999.99',
        ]);
        $this->assertSame('0.01', $first->fresh()->consumption_units);
        $this->assertSame('0.00', $second->fresh()->consumption_units);
        $this->assertSame('9999999999.99', $second->fresh()->previous_reading);
        $this->assertSame('2026-02-12', $second->period_start);
        $this->expectInvalid(fn () => Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-04-12', 'current_reading' => '10000000000.00',
        ]), 'current_reading');
        $this->assertDatabaseCount('readings', 2);
        $this->artisan('water:audit-observation-intervals')->assertSuccessful();
    }

    public function test_negative_and_decreasing_readings_are_rejected_in_api_and_batch(): void
    {
        $meter = $this->meter();
        $base = ['meter_id' => $meter->id, 'reading_date' => '2026-02-12'];
        $this->postJson('/api/readings', $base + ['current_reading' => '-0.01'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_reading');
        $this->postJson('/api/readings', $base + ['current_reading' => '99.99'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_reading');
        $this->postJson('/api/readings/sync-batch', ['readings' => [$base + ['current_reading' => '-0.01']]])
            ->assertUnprocessable()->assertJsonValidationErrors('readings.0.current_reading');
        $this->postJson('/api/readings/sync-batch', ['readings' => [$base + ['current_reading' => '99.99']]])
            ->assertOk()->assertJsonPath('success_count', 0)->assertJsonPath('results.0.retryable', false);
        $this->assertDatabaseCount('readings', 0);
    }

    public function test_existing_row_consistent_in_isolation_but_broken_anchor_cannot_be_extended(): void
    {
        $meter = $this->meter();
        $first = Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12', 'current_reading' => '135.25',
        ]);
        $second = Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-03-12', 'current_reading' => '148.50',
        ]);
        // Ordinary synthetic audit fixture: individually valid row, wrong
        // predecessor. No real records, privileges or schema are changed.
        DB::table('readings')->where('id', $second->id)->update([
            'previous_reading' => '135.00', 'consumption_units' => '13.50',
        ]);
        $snapshot = DB::table('readings')->orderBy('id')->get()->all();
        $this->artisan('water:audit-observation-intervals')
            ->expectsOutputToContain('BROKEN_OBSERVATION_ANCHOR reading #'.$second->id)->assertFailed();
        $this->expectInvalid(fn () => app(ReadingService::class)
            ->calculateReadingPeriod($meter->id, '2026-04-12'), 'period_start');
        $this->expectInvalid(fn () => Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-04-12', 'current_reading' => '150.00',
        ]), 'period_start');
        $this->assertEquals($snapshot, DB::table('readings')->orderBy('id')->get()->all());
        $this->assertSame('135.25', $first->fresh()->current_reading);
    }
}
