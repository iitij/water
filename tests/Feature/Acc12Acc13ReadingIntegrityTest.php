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

    /** An ordinary synthetic row fixture, individually valid but ambiguous as a chain. */
    private function duplicateObservation(Reading $reading): int
    {
        return DB::table('readings')->insertGetId([
            'meter_id' => $reading->meter_id,
            'reading_date' => $reading->actual_reading_date->copy()->addDay()->toDateString(),
            'actual_reading_date' => $reading->getRawOriginal('actual_reading_date'),
            'period_basis' => 'observation',
            'period_start' => $reading->period_start,
            'period_end' => $reading->period_end,
            'previous_reading' => $reading->previous_reading,
            'current_reading' => $reading->current_reading,
            'consumption_units' => $reading->consumption_units,
            'is_invoiced' => false,
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function readingAndFinancialSnapshot(): array
    {
        $snapshot = [];
        foreach (['readings', 'invoices', 'invoice_tariff_lines', 'transactions', 'payments', 'meters', 'subscribers'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->all();
        }

        return $snapshot;
    }

    public function test_round4c_duplicate_latest_actual_date_blocks_extension_without_writes(): void
    {
        $meter = $this->meter();
        $first = Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12', 'current_reading' => '135.25',
        ]);
        $duplicateId = $this->duplicateObservation($first);
        $before = $this->readingAndFinancialSnapshot();

        $this->artisan('water:audit-observation-intervals')
            ->expectsOutputToContain('DUPLICATE_OBSERVATION_DATE reading #'.$first->id)
            ->expectsOutputToContain('DUPLICATE_OBSERVATION_DATE reading #'.$duplicateId)
            ->assertFailed();
        $this->expectInvalid(fn () => app(ReadingService::class)
            ->calculateReadingPeriod($meter->id, '2026-03-12'), 'period_start');
        $this->expectInvalid(fn () => Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-03-12', 'current_reading' => '148.50',
        ]), 'period_start');
        $this->postJson('/api/readings', [
            'meter_id' => $meter->id, 'reading_date' => '2026-03-12', 'current_reading' => '148.50',
        ])->assertUnprocessable()->assertJsonValidationErrors('period_start');
        $this->assertEquals($before, $this->readingAndFinancialSnapshot());
    }

    public function test_round4c_ambiguous_predecessor_must_not_be_selected_by_id(): void
    {
        $meter = $this->meter();
        $first = Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12', 'current_reading' => '135.25',
        ]);
        Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-03-12', 'current_reading' => '148.50',
        ]);
        $duplicateId = $this->duplicateObservation($first);
        // Both older rows satisfy row invariants; only the higher ID matches
        // the latest row's previous value. The tie must not be resolved by ID.
        DB::table('readings')->where('id', $first->id)->update([
            'current_reading' => '134.25', 'consumption_units' => '34.25',
        ]);
        $this->assertSame(
            $first->getRawOriginal('actual_reading_date'),
            Reading::findOrFail($duplicateId)->getRawOriginal('actual_reading_date')
        );
        $before = $this->readingAndFinancialSnapshot();
        $this->artisan('water:audit-observation-intervals')
            ->expectsOutputToContain('DUPLICATE_OBSERVATION_DATE reading #'.$first->id)
            ->expectsOutputToContain('DUPLICATE_OBSERVATION_DATE reading #'.$duplicateId)
            ->assertFailed();
        $this->assertEquals($before, $this->readingAndFinancialSnapshot());

        $payload = [
            'meter_id' => $meter->id, 'reading_date' => '2026-04-12', 'current_reading' => '150.00',
        ];
        try {
            app(ReadingService::class)->calculateReadingPeriod($meter->id, '2026-04-12');
            $this->fail('Ambiguous predecessor was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['توجد قراءات متعددة في تاريخ الملاحظة السابقة؛ يلزم تدقيق التسلسل.'],
                $e->errors()['period_start'] ?? []
            );
        }
        $this->assertEquals($before, $this->readingAndFinancialSnapshot());
        $this->expectInvalid(fn () => Reading::create($payload), 'period_start');
        $this->assertEquals($before, $this->readingAndFinancialSnapshot());
        $this->postJson('/api/readings', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('period_start');
        $this->assertEquals($before, $this->readingAndFinancialSnapshot());
        $this->postJson('/api/readings/sync-batch', ['readings' => [$payload]])
            ->assertOk()->assertJsonPath('success_count', 0)
            ->assertJsonPath('results.0.success', false)
            ->assertJsonPath('results.0.retryable', false);
        $this->assertEquals($before, $this->readingAndFinancialSnapshot());
    }

    public function test_round4c_whole_chain_audit_detects_older_broken_anchor_without_rewriting(): void
    {
        $meter = $this->meter();
        $first = Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12', 'current_reading' => '135.25',
        ]);
        Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-03-12', 'current_reading' => '148.50',
        ]);
        Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-04-12', 'current_reading' => '150.00',
        ]);
        // The two newest links remain consistent; only a whole-chain audit
        // should discover the older broken installation anchor.
        DB::table('readings')->where('id', $first->id)->update([
            'previous_reading' => '99.00', 'consumption_units' => '36.25',
        ]);
        $before = $this->readingAndFinancialSnapshot();

        $this->artisan('water:audit-observation-intervals')
            ->expectsOutputToContain('BROKEN_OBSERVATION_ANCHOR reading #'.$first->id)
            ->assertFailed();
        $this->assertEquals($before, $this->readingAndFinancialSnapshot());
    }

    public function test_round4c_first_period_uses_documented_service_start_when_installation_is_missing(): void
    {
        $meter = $this->meter('100.25');
        $meter->subscriber->update(['subscription_start_date' => '2026-01-15']);
        $meter->update(['installed_at' => null]);

        $id = $this->postJson('/api/readings', [
            'meter_id' => $meter->id, 'reading_date' => '2026-02-13',
            'actual_reading_date' => '2026-02-12', 'current_reading' => '100.26',
        ])->assertCreated()->json('reading.id');
        $reading = Reading::findOrFail($id);
        $this->assertSame('2026-01-15', $reading->period_start);
        $this->assertSame('2026-02-12', $reading->period_end);
        $this->assertSame('100.25', $reading->previous_reading);
        $this->assertSame('0.01', $reading->consumption_units);
        $this->artisan('water:audit-observation-intervals')->assertSuccessful();
    }

    public function test_round4c_invalid_precision_request_or_batch_leaves_all_rows_unchanged(): void
    {
        $meter = $this->meter();
        $reading = Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12', 'current_reading' => '135.25',
        ]);
        $before = $this->readingAndFinancialSnapshot();

        $this->putJson('/api/readings/'.$reading->id, ['current_reading' => '135.251'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_reading');
        $this->putJson('/api/readings/'.$reading->id, ['previous_reading' => '100.001'])
            ->assertUnprocessable()->assertJsonValidationErrors('previous_reading');
        $this->postJson('/api/readings/sync-batch', ['readings' => [
            ['meter_id' => $meter->id, 'reading_date' => '2026-03-12', 'current_reading' => '148.50'],
            ['meter_id' => $meter->id, 'reading_date' => '2026-04-12', 'current_reading' => '148.501'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('readings.1.current_reading');
        $this->assertEquals($before, $this->readingAndFinancialSnapshot());
    }

    public function test_round4c_mixed_batch_rejects_only_invalid_item_and_preserves_exact_chain(): void
    {
        $meter = $this->meter();
        $first = Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-02-12', 'current_reading' => '135.25',
        ]);
        $firstBefore = DB::table('readings')->where('id', $first->id)->first();
        $before = $this->readingAndFinancialSnapshot();
        $validId = (string) \Illuminate\Support\Str::uuid();
        $invalidId = (string) \Illuminate\Support\Str::uuid();
        $valid = [
            'meter_id' => $meter->id, 'reading_date' => '2026-03-12',
            'current_reading' => '148.50', 'sync_uuid' => $validId,
        ];
        $invalid = [
            'meter_id' => $meter->id, 'reading_date' => '2026-04-12',
            'current_reading' => '148.49', 'sync_uuid' => $invalidId,
        ];
        $this->postJson('/api/readings/sync-batch', ['readings' => [$valid, $invalid]])
            ->assertOk()->assertJsonPath('success_count', 1)->assertJsonPath('failed_count', 1)
            ->assertJsonPath('results.0.success', true)->assertJsonPath('results.1.success', false)
            ->assertJsonPath('results.1.retryable', false);
        $this->assertDatabaseMissing('readings', ['sync_uuid' => $invalidId]);
        $this->assertDatabaseCount('readings', 2);
        $saved = Reading::where('sync_uuid', $validId)->firstOrFail();
        $this->assertSame('2026-02-12', $saved->period_start);
        $this->assertSame('13.25', $saved->consumption_units);
        $this->assertEquals($firstBefore, DB::table('readings')->where('id', $first->id)->first());
        $after = $this->readingAndFinancialSnapshot();
        foreach (array_diff(array_keys($before), ['readings']) as $table) {
            $this->assertEquals($before[$table], $after[$table], $table);
        }

        // Batch semantics are per-item, not all-or-nothing. A retry of the
        // accepted UUID and rejected item must not change either outcome.
        $this->postJson('/api/readings/sync-batch', ['readings' => [$valid, $invalid]])
            ->assertOk()->assertJsonPath('results.0.duplicate', true)
            ->assertJsonPath('results.1.retryable', false);
        $this->assertEquals($after, $this->readingAndFinancialSnapshot());
        $this->artisan('water:audit-observation-intervals')->assertSuccessful();
    }

}
