<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_lines_match_individually_without_changing_financial_postings(): void
    {
        $role = Role::findOrCreate('super_admin', 'web');
        $importer = User::factory()->create(['role' => 'admin']);
        $importer->assignRole($role);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $reviewer->assignRole($role);
        $date = today()->toDateString();
        $register = app(TreasuryRegisterService::class);
        $cash = $register->open($importer, ['code' => 'CASH-REC', 'name' => 'Cash source', 'channel' => 'cash',
            'opening_on' => $date, 'opening_amount' => '1000', 'opening_reference' => 'OPEN-REC-CASH',
            'opening_evidence' => 'Independent physical opening count']);
        $bank = $register->open($importer, ['code' => 'BANK-REC', 'name' => 'Bank destination', 'channel' => 'bank',
            'opening_on' => $date, 'opening_amount' => '100.50', 'opening_reference' => 'OPEN-REC-BANK',
            'opening_evidence' => 'Independent bank opening statement']);
        $transfers = app(TreasuryTransferService::class);
        $transfer = $transfers->dispatch($importer, ['from_account_id' => $cash['id'], 'to_account_id' => $bank['id'],
            'amount' => '125.75', 'reference' => 'REC-OUT', 'dispatched_on' => $date, 'evidence' => 'Actual deposit dispatched']);
        $transfers->receive($reviewer, $transfer['id'], ['amount' => '125.75', 'reference' => 'REC-IN',
            'received_on' => $date, 'evidence' => 'Independent deposit confirmed']);
        $posting = DB::table('treasury_postings')->where('treasury_account_id', $bank['id'])->first();
        $before = DB::table('treasury_postings')->orderBy('id')->get()->toArray();
        $payload = ['reference' => 'BANK-ST-01', 'from_date' => $date, 'to_date' => $date,
            'opening_amount' => '100.50', 'closing_amount' => '226.00', 'evidence' => 'Actual statement with a fee',
            'lines' => [
                ['bank_reference' => 'DEP-001', 'booked_on' => $date, 'amount' => '125.75'],
                ['bank_reference' => 'FEE-001', 'booked_on' => $date, 'amount' => '-0.25'],
            ]];
        $url = '/api/treasury/accounts/'.$bank['id'].'/bank-statements';
        $this->actingAs($importer, 'sanctum')->postJson($url, array_replace($payload, ['closing_amount' => '226.01']))
            ->assertUnprocessable()->assertJsonValidationErrors('closing_amount');
        $this->assertDatabaseCount('bank_statements', 0);
        $this->postJson('/api/treasury/accounts/'.$cash['id'].'/bank-statements', $payload)->assertUnprocessable();
        $response = $this->postJson($url, $payload)->assertOk()
            ->assertJsonPath('closing_difference_minor', -25)
            ->assertJsonPath('matched_count', 0)->assertJsonPath('certified_balance', false);
        $id = $response->json('statement.id');
        $line = DB::table('bank_statement_lines')->where('bank_reference', 'DEP-001')->first();
        $fee = DB::table('bank_statement_lines')->where('bank_reference', 'FEE-001')->first();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('statement.id', $id);
        $this->postJson($url, array_replace($payload, ['reference' => 'OTHER-ST']))->assertStatus(409);
        $matchUrl = '/api/treasury/bank-statements/'.$id.'/matches';
        $match = ['line_id' => $line->id, 'posting_id' => $posting->id, 'evidence' => 'Independent bank document matches deposit'];
        $this->postJson($matchUrl, $match)->assertForbidden();
        $this->actingAs($reviewer, 'sanctum')->postJson($matchUrl, array_replace($match, ['line_id' => $fee->id]))
            ->assertUnprocessable();
        $this->postJson($matchUrl, $match)->assertOk()->assertJsonPath('matched_count', 1)
            ->assertJsonPath('bank_unmatched_minor', -25)->assertJsonPath('ledger_unmatched_minor', 0)
            ->assertJsonPath('unmatched_bank_lines.0.bank_reference', 'FEE-001')
            ->assertJsonPath('closing_difference_minor', -25)->assertJsonPath('fully_matched', false);
        $this->postJson($matchUrl, $match)->assertOk()->assertJsonPath('matched_count', 1);
        $this->getJson('/api/treasury/bank-statements/'.$id)->assertOk()->assertJsonPath('issues', []);
        $this->assertDatabaseCount('bank_statement_matches', 1);
        $this->assertEquals($before, DB::table('treasury_postings')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_zero_net_does_not_hide_unmatched_bank_lines(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $date = today()->toDateString();
        $account = app(TreasuryRegisterService::class)->open($admin, [
            'code' => 'BANK-ZERO', 'name' => 'Bank zero', 'channel' => 'bank', 'opening_on' => $date,
            'opening_amount' => '100', 'opening_reference' => 'ZERO-OPEN', 'opening_evidence' => 'Verified bank opening evidence',
        ]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/treasury/accounts/'.$account['id'].'/bank-statements', [
            'reference' => 'ZERO-STATEMENT', 'from_date' => $date, 'to_date' => $date,
            'opening_amount' => '100', 'closing_amount' => '100', 'evidence' => 'Verified statement with two movements',
            'lines' => [
                ['bank_reference' => 'ZERO-IN', 'booked_on' => $date, 'amount' => '10.25'],
                ['bank_reference' => 'ZERO-OUT', 'booked_on' => $date, 'amount' => '-10.25'],
            ],
        ])->assertOk()->assertJsonPath('closing_difference_minor', 0)
            ->assertJsonPath('bank_unmatched_minor', 0)->assertJsonCount(2, 'unmatched_bank_lines')
            ->assertJsonPath('fully_matched', false)->assertJsonPath('certified_balance', false);
    }
}
