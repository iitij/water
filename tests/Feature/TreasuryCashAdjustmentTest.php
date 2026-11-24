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

final class TreasuryCashAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $counted): array
    {
        $role = Role::findOrCreate('super_admin', 'web');
        $counter = User::factory()->create(['role' => 'admin']);
        $counter->assignRole($role);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $reviewer->assignRole($role);
        $cash = app(TreasuryRegisterService::class)->open($counter, [
            'code' => 'CASH-ADJ', 'name' => 'Cash test', 'channel' => 'cash', 'opening_on' => today()->toDateString(),
            'opening_amount' => '500.25', 'opening_reference' => 'ADJ-OPEN', 'opening_evidence' => 'Independent physical opening count',
        ]);
        $count = ['counted_amount' => $counted, 'reference' => 'COUNT-ADJ', 'evidence' => 'Physical count independently witnessed'];
        $url = '/api/treasury/accounts/'.$cash['id'].'/cash-counts';
        $id = $this->actingAs($counter, 'sanctum')->postJson($url, $count)->assertOk()->json('count.id');
        return [$counter, $reviewer, $cash, $id, $count];
    }

    private function accept(User $reviewer, int $id): void
    {
        $this->actingAs($reviewer, 'sanctum')->postJson('/api/treasury/cash-counts/'.$id.'/review', [
            'accept' => true, 'evidence' => 'Independent documented count review',
        ])->assertOk();
    }

    private function payload(string $class = 'confirmed_cash_loss'): array
    {
        return ['reference' => 'ADJ-01', 'classification' => $class,
            'reason' => 'Independently investigated specific cash discrepancy',
            'evidence' => 'Independent evidence of the investigated incident'];
    }

    public function test_confirmed_loss_preserves_original_count_and_is_idempotent(): void
    {
        [$counter, $reviewer, $cash, $id] = $this->fixture('490.00');
        $url = '/api/treasury/cash-counts/'.$id.'/adjustment';
        $data = $this->payload();
        $this->postJson($url, $data)->assertForbidden();
        $this->actingAs($reviewer, 'sanctum')->postJson($url, $data)->assertStatus(409);
        $this->accept($reviewer, $id);
        $this->postJson($url, $data + ['amount' => '1'])->assertUnprocessable();
        $this->postJson($url, array_replace($data, ['classification' => 'unattributed_cash_held']))->assertUnprocessable();
        $this->postJson($url, array_replace($data, ['reference' => 'ADJ-OPEN']))->assertStatus(409);
        $result = $this->postJson($url, $data)->assertOk()
            ->assertJsonPath('adjustment.amount_minor', -1025)->assertJsonPath('posting.amount_minor', -1025)
            ->assertJsonPath('observation.count.recorded_minor', 50025)->assertJsonPath('observation.count.variance_minor', -1025)
            ->assertJsonPath('observation.current_recorded_minor', 49000)->assertJsonPath('observation.adjustment_status', 'posted')
            ->assertJsonPath('observation.certified_balance', false)->assertJsonPath('observation.period_closed', false);
        $this->postJson($url, $data)->assertOk()->assertJsonPath('adjustment.id', $result->json('adjustment.id'));
        $this->postJson($url, array_replace($data, ['reference' => 'ADJ-02']))->assertStatus(409);
        $this->getJson('/api/treasury/accounts/'.$cash['id'])->assertOk()
            ->assertJsonPath('provisional_recorded_balance_minor', 49000)->assertJsonPath('issues', []);
        $this->assertDatabaseCount('treasury_cash_adjustments', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('payments', 0);

        DB::table('treasury_cash_adjustments')->where('id', $result->json('adjustment.id'))->update(['amount_minor' => -1026]);
        $this->getJson('/api/treasury/accounts/'.$cash['id'])->assertOk()
            ->assertJsonPath('issues.0.code', 'cash_adjustment_source_mismatch');
        $this->getJson('/api/treasury/cash-counts/'.$id)->assertOk()->assertJsonPath('adjustment_status', 'inconsistent');
        $this->postJson($url, $data)->assertStatus(409);
    }

    public function test_surplus_is_held_not_revenue_and_newer_count_blocks_old_adjustment(): void
    {
        [$counter, $reviewer, $cash, $id, $count] = $this->fixture('510.50');
        $this->accept($reviewer, $id);
        $new = $this->actingAs($counter, 'sanctum')->postJson('/api/treasury/accounts/'.$cash['id'].'/cash-counts',
            array_replace($count, ['reference' => 'COUNT-ADJ-NEW']))->assertOk()->json('count.id');
        $this->accept($reviewer, $new);
        $data = $this->payload('unattributed_cash_held');
        $this->postJson('/api/treasury/cash-counts/'.$id.'/adjustment', $data)->assertStatus(409);
        $this->postJson('/api/treasury/cash-counts/'.$new.'/adjustment', $data)->assertOk()
            ->assertJsonPath('posting.amount_minor', 1025);
        $this->getJson('/api/treasury/accounts/'.$cash['id'])->assertOk()
            ->assertJsonPath('provisional_recorded_balance_minor', 51050)
            ->assertJsonPath('unattributed_cash_held_minor', 1025)->assertJsonPath('issues', []);
        $this->assertDatabaseCount('treasury_cash_adjustments', 1);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('payments', 0);

        // Later actual movements must not invalidate the original adjustment.
        $bank = app(TreasuryRegisterService::class)->open($counter, [
            'code' => 'BANK-ADJ', 'name' => 'Bank test', 'channel' => 'bank', 'opening_on' => today()->toDateString(),
            'opening_amount' => '0', 'opening_reference' => 'ADJ-BANK-OPEN', 'opening_evidence' => 'Independent bank statement',
        ]);
        app(TreasuryTransferService::class)->dispatch($counter, ['from_account_id' => $cash['id'], 'to_account_id' => $bank['id'],
            'amount' => '10', 'reference' => 'ADJ-TRANSFER', 'dispatched_on' => today()->toDateString(), 'evidence' => 'Actual cash delivered for bank deposit']);
        $this->getJson('/api/treasury/cash-counts/'.$new)->assertOk()
            ->assertJsonPath('adjustment_status', 'posted')->assertJsonPath('ledger_snapshot_matches', false);
        $this->getJson('/api/treasury/accounts/'.$cash['id'])->assertOk()->assertJsonPath('issues', []);
    }

    public function test_stale_count_and_failed_audit_do_not_post_adjustments(): void
    {
        [$counter, $reviewer, $cash, $id] = $this->fixture('490.00');
        $this->accept($reviewer, $id);
        $this->mock(AuditLogger::class, function ($mock) {
            $mock->shouldReceive('recordRequired')->once()->with('treasury.cash_count_adjusted', \Mockery::type('array'))
                ->andThrow(new \RuntimeException('Synthetic required audit failure'));
        });
        $this->postJson('/api/treasury/cash-counts/'.$id.'/adjustment', $this->payload())->assertServerError();
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);

        // Direct mutation is a test fixture for the source snapshot guard.
        DB::table('treasury_accounts')->where('id', $cash['id'])->update(['name' => 'Changed account']);
        $this->postJson('/api/treasury/cash-counts/'.$id.'/adjustment', $this->payload())->assertStatus(409);
        $this->assertDatabaseCount('treasury_cash_adjustments', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
    }
}
