<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TreasuryBankFeeTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $role = Role::findOrCreate('super_admin', 'web');
        $importer = User::factory()->create(['role' => 'admin']);
        $importer->assignRole($role);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $reviewer->assignRole($role);
        $date = today()->toDateString();
        $register = app(TreasuryRegisterService::class);
        $bank = $register->open($importer, ['code' => 'BANK-FEE', 'name' => 'Test bank', 'channel' => 'bank',
            'opening_on' => $date, 'opening_amount' => '100.50', 'opening_reference' => 'FEE-OPEN',
            'opening_evidence' => 'Independent bank opening statement']);
        $id = $this->actingAs($importer, 'sanctum')->postJson('/api/treasury/accounts/'.$bank['id'].'/bank-statements', [
            'reference' => 'FEE-STMT', 'from_date' => $date, 'to_date' => $date,
            'opening_amount' => '100.50', 'closing_amount' => '105.25', 'evidence' => 'Actual bank statement with charge',
            'lines' => [
                ['bank_reference' => 'FEE-DEBIT', 'booked_on' => $date, 'amount' => '-0.25', 'description' => 'Bank service fee'],
                ['bank_reference' => 'UNEXPLAINED-CREDIT', 'booked_on' => $date, 'amount' => '5.00'],
            ],
        ])->assertOk()->json('statement.id');
        $line = DB::table('bank_statement_lines')->where('bank_reference', 'FEE-DEBIT')->first();
        $data = ['line_id' => $line->id, 'reference' => 'FEE-EVIDENCE-01', 'reason' => 'Independent bank service fee',
            'evidence' => 'Bank charge advice verified independently'];
        return [$importer, $reviewer, $bank, $id, $data];
    }

    public function test_fee_posting_and_match_are_atomic_idempotent_and_do_not_hide_other_movements(): void
    {
        [$importer, $reviewer, $bank, $id, $data] = $this->fixture();
        $url = '/api/treasury/bank-statements/'.$id.'/fees';
        $this->postJson($url, $data)->assertForbidden();
        $this->actingAs($reviewer, 'sanctum')->postJson($url, $data + ['amount' => '0.26'])->assertUnprocessable();
        $credit = DB::table('bank_statement_lines')->where('bank_reference', 'UNEXPLAINED-CREDIT')->value('id');
        $this->postJson($url, array_replace($data, ['line_id' => $credit]))->assertUnprocessable();
        $this->postJson($url, array_replace($data, ['reference' => 'FEE-OPEN']))->assertStatus(409);
        $result = $this->postJson($url, $data)->assertOk()
            ->assertJsonPath('fee.amount_minor', 25)->assertJsonPath('posting.amount_minor', -25)
            ->assertJsonPath('reconciliation.matched_count', 1)
            ->assertJsonPath('reconciliation.closing_difference_minor', 500)
            ->assertJsonPath('reconciliation.unmatched_bank_lines.0.bank_reference', 'UNEXPLAINED-CREDIT')
            ->assertJsonPath('reconciliation.fully_matched', false)
            ->assertJsonPath('reconciliation.certified_balance', false);
        $this->postJson($url, $data)->assertOk()->assertJsonPath('fee.id', $result->json('fee.id'));
        $this->postJson($url, array_replace($data, ['reason' => 'Different reason for the charge']))->assertStatus(409);
        $this->getJson('/api/treasury/accounts/'.$bank['id'])->assertOk()
            ->assertJsonPath('provisional_recorded_balance_minor', 10025)->assertJsonPath('issues', []);
        $this->assertDatabaseCount('treasury_bank_fees', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('bank_statement_matches', 1);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('payments', 0);

        // Raw corruption fixture only: the application audit must detect an altered fee source.
        DB::table('treasury_bank_fees')->where('id', $result->json('fee.id'))->update(['amount_minor' => 26]);
        $this->getJson('/api/treasury/accounts/'.$bank['id'])->assertOk()->assertJsonPath('issues.0.code', 'bank_fee_source_mismatch');
        $this->getJson('/api/treasury/bank-statements/'.$id)->assertOk()->assertJsonPath('issues.0.code', 'invalid_match');
        $this->postJson($url, $data)->assertStatus(409);
    }

    public function test_existing_unmatched_debit_must_be_reviewed_before_fee_posting(): void
    {
        [$importer, $reviewer, $bank, $id, $data] = $this->fixture();
        $cash = app(TreasuryRegisterService::class)->open($importer, ['code' => 'CASH-FEE', 'name' => 'Test cash', 'channel' => 'cash',
            'opening_on' => today()->toDateString(), 'opening_amount' => '0', 'opening_reference' => 'CASH-FEE-OPEN',
            'opening_evidence' => 'Independent physical count']);
        $transfer = app(TreasuryTransferService::class)->dispatch($importer, [
            'from_account_id' => $bank['id'], 'to_account_id' => $cash['id'], 'amount' => '0.25',
            'reference' => 'OTHER-DEBIT', 'dispatched_on' => today()->toDateString(),
            'evidence' => 'Actual withdrawal evidenced independently',
        ]);
        $url = '/api/treasury/bank-statements/'.$id.'/fees';
        $this->actingAs($reviewer, 'sanctum')->postJson($url, $data)->assertStatus(409);
        $posting = DB::table('treasury_postings')->where('treasury_transfer_id', $transfer['id'])->first();
        $this->postJson('/api/treasury/bank-statements/'.$id.'/matches', [
            'line_id' => $data['line_id'], 'posting_id' => $posting->id, 'evidence' => 'Independent confirmation of the withdrawal',
        ])->assertOk();
        $this->postJson($url, $data)->assertStatus(409);
        $this->assertDatabaseCount('treasury_bank_fees', 0);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('bank_statement_matches', 1);
    }

    public function test_fee_rolls_back_if_required_audit_fails(): void
    {
        [, $reviewer, , $id, $data] = $this->fixture();
        $this->mock(AuditLogger::class, function ($mock) {
            $mock->shouldReceive('recordRequired')->once()->with('treasury.bank_fee_recorded', \Mockery::type('array'))
                ->andThrow(new \RuntimeException('Synthetic mandatory audit failure'));
        });
        $this->actingAs($reviewer, 'sanctum')->postJson('/api/treasury/bank-statements/'.$id.'/fees', $data)->assertServerError();
        $this->assertDatabaseCount('treasury_bank_fees', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('bank_statement_matches', 0);
    }
}
