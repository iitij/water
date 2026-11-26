<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TreasuryRegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TreasuryTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_and_independent_receipt_conserve_cash_in_transit_and_reject_replay_conflicts(): void
    {
        $role = Role::findOrCreate('super_admin', 'web');
        $sender = User::factory()->create(['role' => 'admin']);
        $sender->assignRole($role);
        $receiver = User::factory()->create(['role' => 'admin']);
        $receiver->assignRole($role);
        $date = today()->toDateString();
        $register = app(TreasuryRegisterService::class);
        $from = $register->open($sender, ['code' => 'CASH-TR', 'name' => 'Cash source', 'channel' => 'cash',
            'opening_on' => $date, 'opening_amount' => '500.25', 'opening_reference' => 'OPEN-TR-CASH',
            'opening_evidence' => 'Independent physical opening count']);
        $to = $register->open($sender, ['code' => 'BANK-TR', 'name' => 'Bank destination', 'channel' => 'bank',
            'opening_on' => $date, 'opening_amount' => '100.50', 'opening_reference' => 'OPEN-TR-BANK',
            'opening_evidence' => 'Independent bank opening statement']);
        $data = ['from_account_id' => $from['id'], 'to_account_id' => $to['id'], 'amount' => '125.75',
            'reference' => 'TRANSFER-OUT-01', 'dispatched_on' => $date, 'evidence' => 'Actual cash dispatched for bank deposit'];
        $this->actingAs($sender, 'sanctum')->postJson('/api/treasury/transfers', array_replace($data,
            ['to_account_id' => $from['id']]))->assertUnprocessable();
        $id = $this->postJson('/api/treasury/transfers', $data)->assertOk()
            ->assertJsonPath('transfer.status', 'in_transit')->assertJsonPath('transfer.in_transit_minor', 12575)->json('transfer.id');
        $this->postJson('/api/treasury/transfers', $data)->assertOk()->assertJsonPath('transfer.id', $id);
        $this->postJson('/api/treasury/transfers', array_replace($data, ['amount' => '125.76']))->assertStatus(409);
        $this->getJson('/api/treasury/accounts/'.$from['id'])->assertOk()->assertJsonPath('provisional_recorded_balance_minor', 37450);
        $this->getJson('/api/treasury/accounts/'.$to['id'])->assertOk()->assertJsonPath('provisional_recorded_balance_minor', 10050);
        $this->getJson('/api/treasury/transfers')->assertOk()->assertJsonPath('in_transit_minor', 12575);
        $receipt = ['amount' => '125.75', 'reference' => 'TRANSFER-IN-01', 'received_on' => $date,
            'evidence' => 'Independent bank credit confirmation'];
        $endpoint = '/api/treasury/transfers/'.$id.'/receive';
        $this->postJson($endpoint, $receipt)->assertForbidden();
        $this->actingAs($receiver, 'sanctum')->postJson($endpoint, array_replace($receipt, ['amount' => '125.74']))->assertUnprocessable();
        $this->postJson($endpoint, array_replace($receipt, ['reference' => $data['reference']]))->assertStatus(409);
        $this->postJson($endpoint, $receipt)->assertOk()->assertJsonPath('transfer.status', 'received')
            ->assertJsonPath('transfer.in_transit_minor', 0)->assertJsonPath('transfer.issues', []);
        $this->postJson($endpoint, $receipt)->assertOk()->assertJsonPath('transfer.id', $id);
        $this->postJson($endpoint, array_replace($receipt, ['reference' => 'SECOND-RECEIPT']))->assertStatus(409);
        $this->getJson('/api/treasury/accounts/'.$from['id'])->assertOk()->assertJsonPath('provisional_recorded_balance_minor', 37450)
            ->assertJsonPath('issues', []);
        $this->getJson('/api/treasury/accounts/'.$to['id'])->assertOk()->assertJsonPath('provisional_recorded_balance_minor', 22625)
            ->assertJsonPath('issues', []);
        $this->getJson('/api/treasury/transfers')->assertOk()->assertJsonPath('in_transit_minor', 0);
        $this->assertDatabaseCount('treasury_transfers', 1);
        $this->assertDatabaseCount('treasury_transfer_receipts', 1);
        $this->assertDatabaseCount('treasury_postings', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }
}
