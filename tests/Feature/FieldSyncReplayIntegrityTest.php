<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meter;
use App\Models\Square;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FieldSyncReplayIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $other;
    private int $meterId;
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const NEW_UUID = '00000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->owner = User::factory()->create(['role' => 'admin']);
        $this->other = User::factory()->create(['role' => 'admin']);
        $this->owner->assignRole('super_admin');
        $this->other->assignRole('super_admin');
        $subscriber = Square::create(['name' => 'Synthetic field sync'])->subscribers()
            ->create(['name' => 'Synthetic account', 'status' => 'active']);
        $this->meterId = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'FIELD-QA'])->id;
    }

    private function fault(array $changes = []): array
    {
        return array_replace(['meter_id' => $this->meterId, 'reading_date' => '2026-11-01',
            'fault_type' => 'meter_broken', 'notes' => 'Synthetic evidence',
            'recorded_at' => '2026-11-01 10:30:00', 'sync_uuid' => self::UUID], $changes);
    }

    private function visit(array $changes = []): array
    {
        return array_replace(['meter_id' => $this->meterId, 'route_date' => '2026-11-01',
            'meter_code' => 'FIELD-QA', 'status' => 'payment_collected',
            'collected_amount' => '10.25', 'notes' => 'Synthetic visit',
            'visit_at' => '2026-11-01 10:30:00', 'sync_uuid' => self::UUID], $changes);
    }

    public function test_fault_replay_preserves_owner_and_immutable_payload(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/readings/faults', $this->fault())
            ->assertCreated()->assertJsonPath('duplicate', false);
        $before = DB::table('reading_faults')->first();
        $this->postJson('/api/readings/faults', $this->fault())
            ->assertCreated()->assertJsonPath('duplicate', true);
        $this->postJson('/api/readings/faults', $this->fault(['notes' => 'Changed']))
            ->assertConflict()->assertJsonMissingPath('fault');
        $this->actingAs($this->other, 'sanctum')->postJson('/api/readings/faults', $this->fault())
            ->assertConflict()->assertJsonMissingPath('fault');
        $this->assertSame(1, DB::table('reading_faults')->count());
        $this->assertEquals($before, DB::table('reading_faults')->first());
    }

    public function test_visit_replay_exact_cents_and_owner_without_rewriting_history(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/route-visits', $this->visit())
            ->assertCreated()->assertJsonPath('duplicate', false);
        $before = DB::table('route_visits')->first();
        $this->postJson('/api/route-visits', $this->visit(['collected_amount' => 10.25]))
            ->assertCreated()->assertJsonPath('duplicate', true);
        $this->postJson('/api/route-visits', $this->visit(['collected_amount' => '10.26']))
            ->assertConflict()->assertJsonMissingPath('visit');
        $this->postJson('/api/route-visits', $this->visit(['collected_amount' => '10.251']))
            ->assertUnprocessable();
        $this->actingAs($this->other, 'sanctum')->postJson('/api/route-visits', $this->visit())
            ->assertConflict()->assertJsonMissingPath('visit');
        $this->assertSame(1, DB::table('route_visits')->count());
        $this->assertEquals($before, DB::table('route_visits')->first());
    }

    public function test_conflicting_fault_batch_rolls_back_prior_new_row(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/readings/faults', $this->fault())->assertCreated();
        $this->actingAs($this->other, 'sanctum')->postJson('/api/readings/faults/sync-batch', [
            'faults' => [$this->fault(['sync_uuid' => self::NEW_UUID]), $this->fault()],
        ])->assertConflict();
        $this->assertSame(1, DB::table('reading_faults')->count());
        $this->assertDatabaseMissing('reading_faults', ['sync_uuid' => self::NEW_UUID]);
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/readings/faults/sync-batch', [
            'faults' => [$this->fault(['sync_uuid' => self::NEW_UUID]), $this->fault()],
        ])->assertOk()->assertJsonPath('synced_count', 1)->assertJsonPath('duplicate_count', 1);
        $this->assertSame(2, DB::table('reading_faults')->count());
    }

    public function test_invalid_visit_batch_rolls_back_all_new_rows_and_preserves_acknowledgement_counts(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/route-visits/sync-batch', [
            'visits' => [$this->visit(['sync_uuid' => self::NEW_UUID]), $this->visit(['collected_amount' => '10000000000.00'])],
        ])->assertUnprocessable();
        $this->assertSame(0, DB::table('route_visits')->count());
        $this->postJson('/api/route-visits/sync-batch', [
            'visits' => [$this->visit(['sync_uuid' => self::NEW_UUID]), $this->visit()],
        ])->assertOk()->assertJsonPath('synced_count', 2)->assertJsonPath('duplicate_count', 0);
        $this->postJson('/api/route-visits/sync-batch', [
            'visits' => [$this->visit(['sync_uuid' => self::NEW_UUID]), $this->visit()],
        ])->assertOk()->assertJsonPath('synced_count', 0)->assertJsonPath('duplicate_count', 2);
        $this->assertSame(2, DB::table('route_visits')->count());
    }
}
