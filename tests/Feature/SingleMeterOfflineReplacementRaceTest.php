<?php

namespace Tests\Feature;

use App\Models\Meter;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cross-device race: one device caches an old meter while another replaces it.
 * This complements device acceptance in #37; it does not replace real-device testing.
 */
class SingleMeterOfflineReplacementRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
    }

    public function test_stale_offline_reading_is_rejected_without_poisoning_current_meter_or_batch(): void
    {
        $square = Square::create(['name' => 'مربع اختبار تعارض المزامنة']);
        $subscriber = Subscriber::create([
            'name' => 'مشترك اختبار الاستبدال دون اتصال',
            'square_id' => $square->id,
            'status' => 'active',
        ]);
        $old = Meter::create([
            'subscriber_id' => $subscriber->id,
            'unique_code' => 'RACE-OLD',
        ]);

        // Device B replaces the meter while device A still has an unsent old reading.
        $currentId = $this->postJson('/api/meters/'.$old->id.'/replace', [
            'removed_at' => now()->toDateString(),
            'reason' => 'استبدال عداد أثناء انقطاع اتصال القارئ',
            'new_meter' => ['unique_code' => 'RACE-NEW', 'initial_reading' => 0],
        ])->assertCreated()->json('current_meter.id');

        $oldUuid = (string) Str::uuid();
        $newUuid = (string) Str::uuid();
        $readings = [
            [
                'meter_id' => $old->id,
                'reading_date' => now()->toDateString(),
                'current_reading' => 20,
                'sync_uuid' => $oldUuid,
            ],
            [
                'meter_id' => $currentId,
                'reading_date' => now()->toDateString(),
                'current_reading' => 10,
                'sync_uuid' => $newUuid,
            ],
        ];

        // The per-item HTTP 200 envelope must preserve both outcomes.
        $this->postJson('/api/readings/sync-batch', ['readings' => $readings])
            ->assertOk()
            ->assertJsonPath('processed_count', 2)
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('results.0.success', false)
            ->assertJsonPath('results.0.retryable', false)
            ->assertJsonPath('results.0.sync_uuid', $oldUuid)
            ->assertJsonPath('results.1.success', true)
            ->assertJsonPath('results.1.duplicate', false)
            ->assertJsonPath('results.1.sync_uuid', $newUuid)
            ->assertJsonPath('results.1.reading.meter_id', $currentId);

        $this->assertDatabaseMissing('readings', ['meter_id' => $old->id]);
        $this->assertDatabaseHas('readings', [
            'meter_id' => $currentId,
            'sync_uuid' => $newUuid,
        ]);
        $this->assertSame(1, $subscriber->fresh()->historicalMeters()->count());
        $this->assertSame($currentId, $subscriber->fresh()->currentMeter->id);

        // Replaying the same batch must never create a second reading on the new meter.
        $this->postJson('/api/readings/sync-batch', ['readings' => $readings])
            ->assertOk()
            ->assertJsonPath('results.0.success', false)
            ->assertJsonPath('results.0.retryable', false)
            ->assertJsonPath('results.1.success', true)
            ->assertJsonPath('results.1.duplicate', true);
        $this->assertDatabaseCount('readings', 1);
        $this->assertDatabaseMissing('readings', ['meter_id' => $old->id]);
    }
}
