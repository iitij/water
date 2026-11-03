<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListFiltersParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_addition_period_is_inclusive_before_pagination_and_distinct_from_subscription_date(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $square = Square::create(['name' => 'Square']);
        $ids = [];
        foreach (['2026-11-01 23:59:59', '2026-11-02 00:00:00', '2026-11-03 23:59:59', '2026-11-04 00:00:00'] as $index => $date) {
            $subscriber = Subscriber::create(['name' => 'Sub '.$index, 'square_id' => $square->id,
                'subscription_start_date' => '2020-01-01', 'status' => 'active']);
            $subscriber->forceFill(['created_at' => $date])->save();
            $ids[] = $subscriber->id;
        }
        $uri = '/api/subscribers?added_from=2026-11-02&added_to=2026-11-03&sort_by=created_at&sort_direction=asc&per_page=1';
        $this->getJson($uri)->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $ids[1]);
        $this->getJson($uri.'&page=2')->assertOk()->assertJsonPath('data.0.id', $ids[2]);
        $this->getJson('/api/subscribers?added_to=2026-11-01')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_exact_path_matches_secondary_meters_and_combines_with_square_and_addition_period(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $square = Square::create(['name' => 'Square']);
        $otherSquare = Square::create(['name' => 'Other']);
        foreach ([[$square, [2, 10]], [$square, [1]], [$otherSquare, [10]]] as $index => [$area, $paths]) {
            $subscriber = Subscriber::create(['name' => 'Sub '.$index, 'square_id' => $area->id, 'status' => 'active']);
            $subscriber->forceFill(['created_at' => '2026-11-03 12:00:00'])->save();
            foreach ($paths as $path) {
                $subscriber->meters()->create(['unique_code' => $index.'-'.$path, 'path_number' => $path, 'status' => 'active']);
            }
        }
        $this->getJson('/api/subscribers?path_number=10&square_id='.$square->id.'&added_from=2026-11-03')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Sub 0');
        $this->getJson('/api/meters?path_number=1')->assertOk()->assertJsonPath('total', 1);
        $subscriber->meters()->create(['unique_code' => 'ZERO-PATH', 'path_number' => 0, 'status' => 'active']);
        $this->getJson('/api/meters?path_number=0')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/subscribers?path_number=0')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_finance_route_sort_and_creation_filter_work_on_all_server_pages(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $square = Square::create(['name' => 'Square']);
        $subscriber = Subscriber::create(['name' => 'Sub', 'square_id' => $square->id, 'status' => 'active']);
        foreach ([10, 2] as $path) {
            $meter = $subscriber->meters()->create(['unique_code' => 'M-'.$path, 'path_number' => $path, 'status' => 'active']);
            $invoice = Invoice::withoutEvents(fn () => Invoice::create(['subscriber_id' => $subscriber->id,
                'meter_id' => $meter->id, 'invoice_type' => 'manual', 'bill_number' => 'FILTER-'.$path,
                'bill_date' => '2020-01-01', 'total_amount' => 90, 'status' => 'issued']));
            $invoice->forceFill(['created_at' => '2026-11-03 23:59:59'])->saveQuietly();
        }
        $this->getJson('/api/invoices?sort_by=path_number&sort_direction=asc&per_page=1&added_from=2026-11-03&added_to=2026-11-03')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.meter.path_number', 2);
        $this->getJson('/api/invoices?path_number=10')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_invalid_filters_are_rejected_and_permissions_still_apply(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        foreach (['path_number=1%20OR%201=1', 'path_number=-1', 'added_from=tomorrow',
            'added_from=2026-11-04&added_to=2026-11-03'] as $query) {
            $this->getJson('/api/subscribers?'.$query)->assertUnprocessable();
        }
        $this->actingAs(User::factory()->create(['role' => 'reader']), 'sanctum');
        $this->getJson('/api/payments?path_number=10&added_from=2026-11-03')->assertForbidden();
        $this->getJson('/api/users?added_from=2026-11-03')->assertForbidden();
    }
}
