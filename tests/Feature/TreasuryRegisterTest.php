<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CashSessionService;
use App\Services\CashHandoverService;
use App\Models\Square;
use App\Models\Subscriber;
use App\Services\PaymentService;
use App\Services\PaymentRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TreasuryRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_register_is_separate_from_subscriber_ledger_and_prevents_double_posting(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $collector = User::factory()->create(['role' => 'admin']);
        $date = today()->toDateString();
        $opening = ['code' => 'CASH-01', 'name' => 'Central cash', 'channel' => 'cash',
            'opening_on' => $date, 'opening_amount' => '100.25',
            'opening_reference' => 'OPEN-01', 'opening_evidence' => 'Verified count immediately before start of day'];
        $this->actingAs($collector, 'sanctum')->postJson('/api/treasury/accounts', $opening)->assertForbidden();
        $this->actingAs($admin, 'sanctum');
        $account = $this->postJson('/api/treasury/accounts', $opening)->assertOk()->json('account.id');
        $this->postJson('/api/treasury/accounts', $opening)->assertOk()->assertJsonPath('account.id', $account);
        $bank = $this->postJson('/api/treasury/accounts', array_replace($opening,
            ['code' => 'BANK-01', 'channel' => 'bank', 'opening_reference' => 'BANK-OPEN']))->assertOk()->json('account.id');
        $other = $this->postJson('/api/treasury/accounts', array_replace($opening,
            ['code' => 'CASH-02', 'opening_reference' => 'OPEN-02']))->assertOk()->json('account.id');

        $sessions = app(CashSessionService::class);
        $session = $sessions->decide($sessions->close($sessions->open($collector), 50.75), true, $admin);
        $handover = app(CashHandoverService::class)->record($session, $admin, 50.75, 'H-01', $date, 'Verified cash independently received');
        $this->getJson('/api/treasury/accounts/'.$account)->assertOk()
            ->assertJsonPath('channel_unposted_handover_count', 1)->assertJsonPath('certified_balance', false);
        $payload = ['kind' => 'cash_handover', 'source_id' => $handover->id];
        $this->postJson('/api/treasury/accounts/'.$bank.'/postings', $payload)->assertUnprocessable();
        $id = $this->postJson('/api/treasury/accounts/'.$account.'/postings', $payload)->assertOk()->json('posting.id');
        $this->postJson('/api/treasury/accounts/'.$account.'/postings', $payload)->assertOk()->assertJsonPath('posting.id', $id);
        $this->postJson('/api/treasury/accounts/'.$other.'/postings', $payload)->assertStatus(409);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);

        $subscriber = Subscriber::create(['name' => 'Synthetic treasury refund', 'square_id' => Square::create(['name' => 'Treasury'])->id, 'status' => 'active']);
        $payment = app(PaymentService::class)->collectPayment($subscriber, 100);
        $refund = app(PaymentRefundService::class)->record($payment, $admin, '25.50', 'REF-01', 'treasury_cash', $date,
            'Synthetic refund of excess credit', 'Verified actual cash disbursement');
        $this->getJson('/api/treasury/accounts/'.$account)->assertOk()->assertJsonPath('channel_unposted_refund_count', 1);
        $this->postJson('/api/treasury/accounts/'.$account.'/postings',
            ['kind' => 'payment_refund', 'source_id' => $refund->id])->assertOk()->assertJsonPath('posting.amount_minor', -2550);
        $this->getJson('/api/treasury/accounts/'.$account)->assertOk()
            ->assertJsonPath('incoming_minor', 5075)->assertJsonPath('outgoing_minor', 2550)
            ->assertJsonPath('provisional_recorded_balance_minor', 12550)
            ->assertJsonPath('channel_unposted_handover_count', 0)->assertJsonPath('channel_unposted_refund_count', 0)
            ->assertJsonPath('certified_balance', false)->assertJsonPath('issues', []);
        $this->assertDatabaseCount('treasury_postings', 2);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertSame(74.5, $subscriber->getAccountBalance());
    }
}
