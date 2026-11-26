<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CashSessionService;
use App\Services\TreasuryRegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CollectorFundingTest extends TestCase
{
    use RefreshDatabase;

    public function test_funding_and_full_return_conserve_cash_without_new_subscriber_postings(): void
    {
        $issuer = User::factory()->create(['role' => 'admin']);
        $issuer->assignRole(Role::findOrCreate('super_admin', 'web'));
        $collector = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();
        $register = app(TreasuryRegisterService::class);
        $account = $register->open($issuer, [
            'code' => 'CASH-FUND', 'name' => 'Test cash', 'channel' => 'cash', 'opening_on' => $date,
            'opening_amount' => '500.25', 'opening_reference' => 'OPEN-FUND',
            'opening_evidence' => 'Independently documented opening count',
        ]);
        $accountId = (int) $account['id'];
        $endpoint = '/api/treasury/accounts/'.$accountId.'/fund-collector';
        $data = ['collector_id' => $collector->id, 'amount' => '100.25',
            'reference' => 'FUND-001', 'evidence' => 'Actual cash delivered to collector'];
        $this->actingAs($collector, 'sanctum')->postJson('/api/cash-sessions', ['opening_amount' => '100.25'])
            ->assertUnprocessable()->assertJsonValidationErrors('opening_amount');
        $this->postJson($endpoint, $data)->assertForbidden();
        $this->actingAs($issuer, 'sanctum')->postJson($endpoint, array_replace($data, ['collector_id' => $issuer->id]))->assertForbidden();
        $sessionId = $this->postJson($endpoint, $data)->assertOk()
            ->assertJsonPath('cash_session.opening_amount', '100.25')
            ->assertJsonPath('cash_session.opening_funding.status', 'documented')->json('cash_session.id');
        $this->postJson($endpoint, $data)->assertOk()->assertJsonPath('cash_session.id', $sessionId);
        $this->postJson($endpoint, array_replace($data, ['amount' => '100.26']))->assertStatus(409);
        $this->postJson($endpoint, array_replace($data, ['reference' => 'FUND-002']))->assertUnprocessable();
        $this->assertDatabaseCount('cash_session_fundings', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('cash_sessions', 1);
        $this->getJson('/api/treasury/accounts/'.$accountId)->assertOk()
            ->assertJsonPath('provisional_recorded_balance_minor', 40000)->assertJsonPath('issues', []);
        $this->actingAs($collector, 'sanctum')->getJson('/api/cash-sessions/current')->assertOk()
            ->assertJsonPath('cash_session.id', $sessionId)->assertJsonPath('cash_session.opening_funding.status', 'documented');
        $this->postJson('/api/cash-sessions/'.$sessionId.'/close', ['counted_amount' => '100.25'])->assertOk()
            ->assertJsonPath('cash_session.variance_amount', '0.00');
        $this->actingAs($issuer, 'sanctum')->postJson('/api/cash-sessions/'.$sessionId.'/approve', ['approve' => true])->assertOk();
        $handoverId = $this->postJson('/api/cash-sessions/'.$sessionId.'/handovers', [
            'amount' => '100.25', 'reference' => 'RETURN-FUND', 'received_on' => $date,
            'evidence' => 'Full opening cash returned to treasury',
        ])->assertOk()->json('handover.id');
        $this->postJson('/api/treasury/accounts/'.$accountId.'/postings', ['kind' => 'cash_handover', 'source_id' => $handoverId])->assertOk();
        $this->getJson('/api/treasury/accounts/'.$accountId)->assertOk()
            ->assertJsonPath('provisional_recorded_balance_minor', 50025)
            ->assertJsonPath('incoming_minor', 10025)->assertJsonPath('outgoing_minor', 10025)
            ->assertJsonPath('issues', [])->assertJsonPath('certified_balance', false);
        $this->postJson($endpoint, $data)->assertOk()->assertJsonPath('cash_session.id', $sessionId)
            ->assertJsonPath('cash_session.state', 'approved');
        $this->assertDatabaseCount('cash_sessions', 1);
        $this->assertDatabaseCount('treasury_postings', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }
}
