<?php

namespace Tests\Feature;

use App\Models\Square;
use App\Models\Subscriber;
use App\Models\SubscriptionFee;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اختبارات وحدات المهندس فؤاد (Dev 4): SCR-04, SCR-05/07, SCR-19, SCR-20, SCR-22.
 */
class FouadModulesApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_squares_crud_and_subscribers_count(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/squares', ['name' => 'مربع الاختبار', 'description' => 'وصف'])
            ->assertCreated();

        $square = Square::first();
        Subscriber::create(['name' => 'مشترك أ', 'square_id' => $square->id, 'status' => 'active']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/squares')
            ->assertOk()
            ->assertJsonPath('0.subscribers_count', 1);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/squares/{$square->id}")
            ->assertOk()
            ->assertJsonPath('subscribers.0.name', 'مشترك أ');

        // مربع له مشتركون لا يُحذف
        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/squares/{$square->id}")
            ->assertStatus(422);
    }

    public function test_subscriber_360_summary_isolates_general_and_meter_balances(): void
    {
        $square = Square::create(['name' => 'مربع 1']);
        $subscriber = Subscriber::create([
            'name' => 'مشترك 360', 'square_id' => $square->id, 'status' => 'active', 'balance' => 2000,
        ]);
        $meter = $subscriber->meters()->create([
            'unique_code' => 'W-1', 'status' => 'active', 'balance' => -9500,
        ]);
        SubscriptionFee::create(['subscriber_id' => $subscriber->id, 'amount' => 15000, 'status' => 'uninvoiced']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/subscribers/{$subscriber->id}")
            ->assertOk()
            ->assertJsonPath('financial_summary.general_balance', 2000)
            ->assertJsonPath('financial_summary.total_credit_balance', 2000)
            ->assertJsonPath('financial_summary.total_debit_balance', 9500)
            ->assertJsonPath('financial_summary.remaining_subscription_fee', 15000)
            ->assertJsonPath('meters.0.meter_debit', 9500);
        $this->assertNotNull($meter);
    }

    public function test_subscriber_transactions_party_filter_and_running_balance(): void
    {
        $square = Square::create(['name' => 'مربع 1']);
        $subscriber = Subscriber::create(['name' => 'مشترك دفتر', 'square_id' => $square->id, 'status' => 'active']);
        Transaction::create(['subscriber_id' => $subscriber->id, 'type' => 'invoice', 'amount' => -3000, 'description' => 'فاتورة عامة']);
        Transaction::create(['subscriber_id' => $subscriber->id, 'type' => 'payment', 'amount' => 3000, 'description' => 'سند قبض']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/subscribers/{$subscriber->id}/transactions?meter_id=general")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.running_balance', 0);
    }

    public function test_service_request_complete_and_ledger_filters(): void
    {
        $square = Square::create(['name' => 'مربع 1']);
        $subscriber = Subscriber::create(['name' => 'مشترك وايت', 'square_id' => $square->id, 'status' => 'active']);
        $request = ServiceRequest::create([
            'subscriber_id' => $subscriber->id, 'service_type' => 'water_tanker',
            'amount' => 7000, 'service_date' => now()->toDateString(), 'status' => 'pending',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/service-requests/{$request->id}/complete")
            ->assertOk()
            ->assertJsonPath('service_request.status', 'completed');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/service-requests?status=completed')
            ->assertOk()
            ->assertJsonPath('total_sum', 7000);

        Transaction::create(['subscriber_id' => $subscriber->id, 'type' => 'payment', 'amount' => 7000, 'description' => 'سند']);
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/transactions?type=credit')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_subscription_fees_summary(): void
    {
        $square = Square::create(['name' => 'مربع 1']);
        $subscriber = Subscriber::create(['name' => 'مشترك رسوم', 'square_id' => $square->id, 'status' => 'active']);
        SubscriptionFee::create(['subscriber_id' => $subscriber->id, 'amount' => 15000, 'status' => 'uninvoiced']);
        SubscriptionFee::create(['subscriber_id' => $subscriber->id, 'amount' => 15000, 'status' => 'paid']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/subscription-fees')
            ->assertOk()
            ->assertJsonPath('total_sum', 30000)
            ->assertJsonPath('remaining_sum', 15000);
    }
}
