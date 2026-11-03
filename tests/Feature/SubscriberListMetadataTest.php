<?php

namespace Tests\Feature;

use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriberListMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
    }

    public function test_list_exposes_all_distinct_numeric_paths_and_actual_creation_time(): void
    {
        $square = Square::create(['name' => 'Square']);
        $subscriber = Subscriber::create([
            'name' => 'Alpha', 'square_id' => $square->id, 'status' => 'active',
            'subscription_start_date' => '2025-01-01',
        ]);
        $subscriber->forceFill(['created_at' => '2026-02-03 04:05:06'])->save();
        foreach ([10, null, 2, 10, 0] as $index => $pathNumber) {
            $subscriber->meters()->create([
                'unique_code' => 'PATH-' . $index,
                'path_number' => $pathNumber,
                'status' => $index === 2 ? 'inactive' : 'active',
            ]);
        }

        $this->getJson('/api/subscribers')->assertOk()
            ->assertJsonPath('data.0.id', $subscriber->id)
            ->assertJsonPath('data.0.meter_number', 'PATH-0')
            ->assertJsonPath('data.0.path_numbers', [0, 2, 10])
            ->assertJsonPath('data.0.created_at', '2026-02-03T04:05:06.000000Z')
            ->assertJsonPath('data.0.subscription_start_date', '2025-01-01');
    }

    public function test_missing_paths_and_creation_timestamp_have_explicit_empty_and_null_values(): void
    {
        $square = Square::create(['name' => 'Square']);
        $subscriber = Subscriber::create(['name' => 'Alpha', 'square_id' => $square->id, 'status' => 'active']);
        $subscriber->forceFill(['created_at' => null])->save();
        Subscriber::create(['name' => 'Beta', 'square_id' => $square->id, 'status' => 'active'])
            ->meters()->create(['unique_code' => 'UNASSIGNED', 'path_number' => null, 'status' => 'active']);

        $this->getJson('/api/subscribers')->assertOk()
            ->assertJsonPath('data.0.path_numbers', [])
            ->assertJsonPath('data.0.created_at', null)
            ->assertJsonPath('data.1.path_numbers', []);
    }

    public function test_search_accepts_arabic_path_digits_and_compound_square_path(): void
    {
        $square = Square::create(['name' => 'Square']);
        $otherSquare = Square::create(['name' => 'Other square']);
        $matching = Subscriber::create(['name' => 'Alpha', 'square_id' => $square->id, 'status' => 'active']);
        $other = Subscriber::create(['name' => 'Beta', 'square_id' => $otherSquare->id, 'status' => 'active']);
        foreach ([$matching, $other] as $subscriber) {
            $subscriber->meters()->create([
                'unique_code' => 'METER-' . $subscriber->id, 'path_number' => 12, 'status' => 'active',
            ]);
        }
        $matching->meters()->create(['unique_code' => 'SECOND', 'path_number' => 3, 'status' => 'active']);

        $this->getJson('/api/subscribers?' . http_build_query(['search' => '١٢']))->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.path_numbers', [3, 12]);
        $compound = strtr($square->id . '/12', ['1' => '١', '2' => '٢']);
        $this->getJson('/api/subscribers?' . http_build_query(['q' => $compound]))->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.path_numbers', [3, 12]);
    }
}
