<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TreasuryCashCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_independent_cash_count_review_preserves_variance_and_rejects_stale_snapshot(): void
    {
        $role = Role::findOrCreate('super_admin', 'web');
        $counter = User::factory()->create(['role' => 'admin']);
        $counter->assignRole($role);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $reviewer->assignRole($role);
        $date = today()->toDateString();
        $register = app(TreasuryRegisterService::class);
        $cash = $register->open($counter, ['code' => 'CASH-COUNT', 'name' => 'Cash count', 'channel' => 'cash',
            'opening_on' => $date, 'opening_amount' => '500.25', 'opening_reference' => 'OPEN-COUNT',
            'opening_evidence' => 'Independent physical opening count']);
        $bank = $register->open($counter, ['code' => 'BANK-COUNT', 'name' => 'Bank destination', 'channel' => 'bank',
            'opening_on' => $date, 'opening_amount' => '100', 'opening_reference' => 'OPEN-COUNT-BANK',
            'opening_evidence' => 'Independent bank opening statement']);
        $url = '/api/treasury/accounts/'.$cash['id'].'/cash-counts';
        $payload = ['counted_amount' => '490.00', 'reference' => 'COUNT-01', 'evidence' => 'Physical count witnessed at treasury'];
        $this->actingAs($counter, 'sanctum')->postJson('/api/treasury/accounts/'.$bank['id'].'/cash-counts', $payload)->assertUnprocessable();
        $id = $this->postJson($url, $payload)->assertOk()
            ->assertJsonPath('count.recorded_minor', 50025)->assertJsonPath('count.variance_minor', -1025)
            ->assertJsonPath('variance_status', 'shortage')->assertJsonPath('status', 'pending_review')
            ->assertJsonPath('ledger_snapshot_matches', true)->assertJsonPath('certified_balance', false)->json('count.id');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('count.id', $id);
        $this->postJson($url, array_replace($payload, ['counted_amount' => '491']))->assertStatus(409);
        $reviewUrl = '/api/treasury/cash-counts/'.$id.'/review';
        $review = ['accept' => true, 'evidence' => 'Independent review of observed count and shortage'];
        $this->postJson($reviewUrl, $review)->assertForbidden();
        $this->actingAs($reviewer, 'sanctum')->postJson($reviewUrl, $review)->assertOk()
            ->assertJsonPath('status', 'accepted_observation')->assertJsonPath('count.variance_minor', -1025)
            ->assertJsonPath('period_closed', false);
        $this->postJson($reviewUrl, $review)->assertOk();
        $this->postJson($reviewUrl, array_replace($review, ['accept' => false]))->assertStatus(409);
        $this->assertDatabaseCount('treasury_postings', 0);
        $pending = $this->actingAs($counter, 'sanctum')->postJson($url, array_replace($payload, ['reference' => 'COUNT-02']))
            ->assertOk()->json('count.id');
        app(TreasuryTransferService::class)->dispatch($counter, ['from_account_id' => $cash['id'], 'to_account_id' => $bank['id'],
            'amount' => '20', 'reference' => 'COUNT-TRANSFER', 'dispatched_on' => $date, 'evidence' => 'Cash delivered for bank deposit']);
        $this->actingAs($reviewer, 'sanctum')->postJson('/api/treasury/cash-counts/'.$pending.'/review', $review)->assertStatus(409);
        $this->getJson('/api/treasury/cash-counts/'.$id)->assertOk()
            ->assertJsonPath('count.variance_minor', -1025)->assertJsonPath('ledger_snapshot_matches', false)
            ->assertJsonPath('current_recorded_minor', 48025)->assertJsonPath('certified_balance', false);
        $this->assertDatabaseCount('treasury_cash_count_reviews', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }
}
