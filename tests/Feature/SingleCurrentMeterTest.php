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
