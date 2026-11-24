<?php

namespace Tests\Feature;

use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentService;
use App\Services\TreasuryRegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class NonCashClaimTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $role = Role::findOrCreate('super_admin', 'web');
        $issuer = User::factory()->create(['role' => 'admin']);
        $issuer->assignRole($role);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $reviewer->assignRole($role);
        $subscriber = Subscriber::create(['name' => 'Synthetic claim', 'square_id' => Square::create(['name' => 'Claims'])->id, 'status' => 'active']);
        $date = today()->toDateString();
        $bank = app(TreasuryRegisterService::class)->open($issuer, [
            'code' => 'CLAIM-BANK', 'name' => 'Claim bank', 'channel' => 'bank', 'opening_on' => $date,
            'opening_amount' => '0', 'opening_reference' => 'CLAIM-OPEN', 'opening_evidence' => 'Independent opening statement',
        ]);
        $stmt = $this->actingAs($issuer, 'sanctum')->postJson('/api/treasury/accounts/'.$bank['id'].'/bank-statements', [
            'reference' => 'CLAIM-STMT', 'from_date' => $date, 'to_date' => $date,
            'opening_amount' => '0', 'closing_amount' => '50.25', 'evidence' => 'Actual bank statement with receipt',
            'lines' => [['bank_reference' => 'CLAIM-IN', 'booked_on' => $date, 'amount' => '50.25']],
        ])->assertOk()->json('statement.id');
        $data = ['sync_uuid' => (string) Str::uuid(), 'subscriber_id' => $subscriber->id, 'amount' => '50.25',
            'payment_method' => 'check', 'instrument_reference' => 'BANK-A-CHECK-001', 'instrument_on' => $date];
        $clear = ['statement_id' => $stmt, 'line_id' => DB::table('bank_statement_lines')->value('id'),
            'reference' => 'CLAIM-PROOF', 'evidence' => 'Independently verified cleared check'];
        return [$issuer, $reviewer, $subscriber, $data, $clear];
    }

    public function test_claim_has_no_financial_effect_until_independent_atomic_clearance(): void
    {
        [$issuer, $reviewer, $subscriber, $data, $clear] = $this->fixture();
        try {
            app(PaymentService::class)->collectPayment($subscriber, 50.25, paymentMethod: 'check', referenceNumber: 'CHECK-OLD');
            $this->fail('Uncleared receipt issued.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_method', $e->errors());
        }
        $id = $this->postJson('/api/noncash-claims', $data)->assertCreated()->assertJsonPath('claim.status', 'pending')->json('claim.id');
        $this->postJson('/api/noncash-claims', $data)->assertCreated()->assertJsonPath('claim.id', $id);
        $this->postJson('/api/noncash-claims', array_replace($data, ['sync_uuid' => (string) Str::uuid()]))->assertStatus(409);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $url = '/api/noncash-claims/'.$id.'/clear';
        $this->postJson($url, $clear)->assertForbidden();
        $result = $this->actingAs($reviewer, 'sanctum')->postJson($url, $clear)->assertOk()
            ->assertJsonPath('claim.status', 'cleared')->assertJsonPath('bank.posting.amount_minor', 5025);
        $this->postJson($url, $clear)->assertOk()->assertJsonPath('payment_id', $result->json('payment_id'));
        $this->postJson('/api/noncash-claims/'.$id.'/reject', ['evidence' => 'Later rejection is not allowed'])->assertStatus(409);
        $this->assertDatabaseHas('payments', ['id' => $result->json('payment_id'), 'cash_session_id' => null, 'sync_uuid' => $data['sync_uuid']]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('bank_statement_matches', 1);
        $this->assertSame(50.25, $subscriber->getAccountBalance());
    }

    public function test_rejection_and_failed_clearance_leave_no_receipt_or_credit(): void
    {
        [$issuer, $reviewer, $subscriber, $data, $clear] = $this->fixture();
        $id = $this->postJson('/api/noncash-claims', array_replace($data, ['amount' => '50.26']))->assertCreated()->json('claim.id');
        $this->actingAs($reviewer, 'sanctum')->postJson('/api/noncash-claims/'.$id.'/clear', $clear)->assertUnprocessable();
        $this->postJson('/api/noncash-claims/'.$id.'/reject', ['evidence' => 'Instrument rejected by independent review'])
            ->assertOk()->assertJsonPath('claim.status', 'rejected');
        $this->postJson('/api/noncash-claims/'.$id.'/clear', $clear)->assertStatus(409);
        $id2 = $this->actingAs($issuer, 'sanctum')->postJson('/api/noncash-claims', array_replace($data,
            ['sync_uuid' => (string) Str::uuid(), 'instrument_reference' => 'BANK-A-CHECK-002']))->assertCreated()->json('claim.id');
        $this->mock(AuditLogger::class, function ($mock) {
            $mock->shouldReceive('recordRequired')->with('treasury.bank_collection_confirmed', \Mockery::type('array'))->once();
            $mock->shouldReceive('recordRequired')->with('noncash_claim.cleared', \Mockery::type('array'))->once()
                ->andThrow(new \RuntimeException('Synthetic required audit failure'));
        });
        $this->actingAs($reviewer, 'sanctum')->postJson('/api/noncash-claims/'.$id2.'/clear', $clear)->assertServerError();
        $this->assertDatabaseHas('noncash_collection_claims', ['id' => $id2, 'status' => 'pending', 'payment_id' => null]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('bank_statement_matches', 0);
    }
}
