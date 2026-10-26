<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSortingParityTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
    }

    public function test_sorting_precedes_pagination_and_equal_values_have_stable_pages(): void
    {
        $this->signIn();
        $square = Square::create(['name' => 'Square']);
        $ids = collect(['Zulu', 'Alpha', 'Alpha', 'Beta'])->map(fn ($name) =>
            Subscriber::create(['name' => $name, 'square_id' => $square->id, 'status' => 'active'])->id
        );
        $query = '/api/subscribers?sort_by=name&sort_direction=asc&per_page=2';
        $this->getJson($query)->assertOk()->assertJsonPath('data.0.id', $ids[1])->assertJsonPath('data.1.id', $ids[2]);
        $this->getJson($query . '&page=2')->assertOk()->assertJsonPath('data.0.id', $ids[3])->assertJsonPath('data.1.id', $ids[0]);
        $this->getJson('/api/subscribers?sort_by=name&sort_direction=desc&per_page=2')
            ->assertOk()->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('data.1.id', $ids[3]);
    }

    public function test_related_and_nested_relation_sorts_keep_each_record_once(): void
    {
        $this->signIn();
        $z = Square::create(['name' => 'Zulu']);
        $a = Square::create(['name' => 'Alpha']);
        $first = Subscriber::create(['name' => 'Alpha', 'square_id' => $z->id, 'status' => 'active']);
        $second = Subscriber::create(['name' => 'Zulu', 'square_id' => $a->id, 'status' => 'active']);
        $one = Meter::create(['unique_code' => 'ONE', 'subscriber_id' => $first->id, 'status' => 'active']);
        $two = Meter::create(['unique_code' => 'TWO', 'subscriber_id' => $second->id, 'status' => 'active']);
        $this->getJson('/api/meters?sort_by=subscriber_name&sort_direction=asc')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $one->id);
        $this->getJson('/api/meters?sort_by=square_name&sort_direction=asc')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $two->id);
        $second->delete();
        $this->getJson('/api/subscribers?sort_by=square_name&sort_direction=asc')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $first->id);
    }

    public function test_count_alias_and_invoice_total_due_sort_use_complete_values(): void
    {
        $this->signIn();
        $square = Square::create(['name' => 'Square']);
        $first = Subscriber::create(['name' => 'Alpha', 'square_id' => $square->id, 'status' => 'active']);
        $second = Subscriber::create(['name' => 'Zulu', 'square_id' => $square->id, 'status' => 'active']);
        Meter::create(['unique_code' => 'ONE', 'subscriber_id' => $second->id, 'status' => 'active']);
        $this->getJson('/api/subscribers?sort_by=meters_count&sort_direction=desc')->assertOk()
            ->assertJsonPath('data.0.id', $second->id);
        $small = Invoice::withoutEvents(fn () => Invoice::create(['subscriber_id' => $first->id, 'invoice_type' => 'manual',
            'bill_number' => 'SORT-1', 'bill_date' => '2026-10-26', 'total_amount' => 90, 'previous_balance' => 0, 'status' => 'unpaid']));
        $large = Invoice::withoutEvents(fn () => Invoice::create(['subscriber_id' => $second->id, 'invoice_type' => 'manual',
            'bill_number' => 'SORT-2', 'bill_date' => '2026-10-26', 'total_amount' => 10, 'previous_balance' => 100, 'status' => 'unpaid']));
        $this->getJson('/api/invoices?sort_by=total_due&sort_direction=desc&per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $large->id)->assertJsonPath('total', 2);
        $this->getJson('/api/invoices?sort_by=total_due&sort_direction=desc&per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.0.id', $small->id);
    }

    public function test_unknown_or_sql_like_sort_fields_and_invalid_direction_are_rejected(): void
    {
        $this->signIn();
        foreach (['name desc; DROP TABLE users', 'users.password', 'unknown'] as $field) {
            $this->getJson('/api/subscribers?' . http_build_query(['sort_by' => $field]))
                ->assertUnprocessable()->assertJsonValidationErrors('sort_by');
        }
        $this->getJson('/api/subscribers?sort_by=name&sort_direction=sideways')
            ->assertUnprocessable()->assertJsonValidationErrors('sort_direction');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_sorting_does_not_bypass_resource_permissions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'reader']), 'sanctum');
        $this->getJson('/api/users?sort_by=email')->assertForbidden();
        $this->getJson('/api/audit-events?sort_by=event')->assertForbidden();
    }
}
