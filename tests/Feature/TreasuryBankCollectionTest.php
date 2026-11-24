<?php

namespace Tests\Feature;

use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentService;
use App\Services\PaymentRefundService;
use App\Services\TreasuryRegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TreasuryBankCollectionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $method): array
    {
        $role = Role::findOrCreate('super_admin', 'web');
        $issuer = User::factory()->create(['role' => 'admin']);
        $issuer->assignRole($role);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $reviewer->assignRole($role);
        $subscriber = Subscriber::create(['name' => 'Synthetic bank collection', 'square_id' => Square::create(['name' => 'Bank collections'])->id, 'status' => 'active']);
        // Seed a pre-existing legacy receipt, not a new noncash issuance.
        $payment = \App\Models\Payment::create(['subscriber_id' => $subscriber->id, 'user_id' => $issuer->id,
            'receipt_number' => 'LEGACY-BANK-01', 'amount' => '125.75', 'payment_date' => today(),
            'payment_method' => $method, 'reference_number' => 'BANK-REF']);
        app(\App\Services\TransactionService::class)->recordTransaction($subscriber, 'payment', 125.75, 'Legacy receipt', $payment);
        app(PaymentService::class)->recalculateInvoices($subscriber);
        $date = today()->toDateString();
        $bank = app(TreasuryRegisterService::class)->open($issuer, [
            'code' => 'BANK-COLLECT', 'name' => 'Test bank', 'channel' => 'bank', 'opening_on' => $date,
            'opening_amount' => '100.50', 'opening_reference' => 'COLLECT-OPEN', 'opening_evidence' => 'Independent opening statement',
        ]);
        $id = $this->actingAs($issuer, 'sanctum')->postJson('/api/treasury/accounts/'.$bank['id'].'/bank-statements', [
            'reference' => 'COLLECT-STMT', 'from_date' => $date, 'to_date' => $date,
            'opening_amount' => '100.50', 'closing_amount' => '231.25', 'evidence' => 'Actual bank statement with receipt',
            'lines' => [
                ['bank_reference' => 'COLLECT-IN', 'booked_on' => $date, 'amount' => '125.75'],
                ['bank_reference' => 'OTHER-IN', 'booked_on' => $date, 'amount' => '5.00'],
            ],
        ])->assertOk()->json('statement.id');
        $data = ['payment_id' => $payment->id, 'line_id' => DB::table('bank_statement_lines')->where('bank_reference', 'COLLECT-IN')->value('id'),
            'reference' => 'COLLECT-PROOF', 'evidence' => 'Independent confirmation of actual bank credit'];
        return [$issuer, $reviewer, $subscriber, $payment, $bank, $id, $data];
    }

    public function test_bank_confirmation_posts_only_treasury_and_blocks_ordinary_void(): void
    {
        [$issuer, $reviewer, $subscriber, $payment, $bank, $id, $data] = $this->fixture('bank_transfer');
        $url = '/api/treasury/bank-statements/'.$id.'/collections';
        $this->postJson($url, $data)->assertForbidden();
        $this->actingAs($reviewer, 'sanctum')->getJson('/api/treasury/accounts/'.$bank['id'])->assertOk()
            ->assertJsonPath('channel_unconfirmed_non_cash_count', 1)->assertJsonPath('provisional_recorded_balance_minor', 10050);
        $this->postJson($url, $data + ['amount' => '1'])->assertUnprocessable();
        $other = DB::table('bank_statement_lines')->where('bank_reference', 'OTHER-IN')->value('id');
        $this->postJson($url, array_replace($data, ['line_id' => $other]))->assertUnprocessable();
        $result = $this->postJson($url, $data)->assertOk()
            ->assertJsonPath('posting.amount_minor', 12575)->assertJsonPath('collection.payment_id', $payment->id)
            ->assertJsonPath('reconciliation.matched_count', 1)->assertJsonPath('reconciliation.closing_difference_minor', 500)
            ->assertJsonPath('reconciliation.certified_balance', false);
        $this->postJson($url, $data)->assertOk()->assertJsonPath('collection.id', $result->json('collection.id'));
        $this->postJson($url, array_replace($data, ['reference' => 'OTHER-PROOF']))->assertStatus(409);
        $this->getJson('/api/treasury/accounts/'.$bank['id'])->assertOk()
            ->assertJsonPath('channel_unconfirmed_non_cash_count', 0)
            ->assertJsonPath('provisional_recorded_balance_minor', 22625)->assertJsonPath('issues', []);
        $this->assertSame(125.75, $subscriber->getAccountBalance());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('treasury_bank_collections', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        try {
            app(PaymentService::class)->voidPayment($payment, 'Ordinary void must be rejected', $reviewer->id);
            $this->fail('Confirmed receipt was voided.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }
        DB::table('treasury_bank_collections')->where('id', $result->json('collection.id'))->update(['amount_minor' => 12576]);
        $this->getJson('/api/treasury/accounts/'.$bank['id'])->assertOk()->assertJsonPath('issues.0.code', 'bank_collection_source_mismatch');
        $this->getJson('/api/treasury/bank-statements/'.$id)->assertOk()->assertJsonPath('issues.0.code', 'invalid_match');
    }

    public function test_unconfirmed_check_cannot_be_refunded_and_confirmed_check_can(): void
    {
        [, $reviewer, $subscriber, $payment, , $id, $data] = $this->fixture('check');
        $refund = fn () => app(PaymentRefundService::class)->record($payment, $reviewer, '10.25', 'CHECK-REFUND',
            'bank_transfer', today()->toDateString(), 'Refund verified excess credit', 'Actual independent disbursement evidence');
        try {
            $refund();
            $this->fail('Uncleared check was refunded.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }
        $this->assertDatabaseCount('payment_refunds', 0);
        $this->actingAs($reviewer, 'sanctum')->postJson('/api/treasury/bank-statements/'.$id.'/collections', $data)
            ->assertOk()->assertJsonPath('collection.payment_method', 'check');
        $refund();
        $this->assertSame(115.5, $subscriber->getAccountBalance());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_cash_receipt_and_failed_audit_do_not_create_bank_confirmation(): void
    {
        [$issuer, $reviewer, $subscriber, , , $id, $data] = $this->fixture('bank_transfer');
        $cash = app(PaymentService::class)->collectPayment($subscriber, 125.75, userId: $issuer->id);
        $url = '/api/treasury/bank-statements/'.$id.'/collections';
        $this->actingAs($reviewer, 'sanctum')->postJson($url, array_replace($data, ['payment_id' => $cash->id]))->assertUnprocessable();
        $this->mock(AuditLogger::class, function ($mock) {
            $mock->shouldReceive('recordRequired')->once()->with('treasury.bank_collection_confirmed', \Mockery::type('array'))
                ->andThrow(new \RuntimeException('Synthetic required audit failure'));
        });
        $this->postJson($url, $data)->assertServerError();
        $this->assertDatabaseCount('treasury_bank_collections', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('bank_statement_matches', 0);
        $this->assertDatabaseCount('transactions', 2);
    }
}
