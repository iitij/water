<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\TreasuryCashCountService;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryTransferService;
use App\Support\TreasuryMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ExactTreasuryI03Test extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshSyntheticDatabase;
    }

    public function refreshDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $driver = DB::getDriverName();
        $database = DB::connection()->getDatabaseName();
        $this->assertTrue(($driver === 'sqlite' && $database === ':memory:')
            || ($driver === 'mysql' && $database === 'water_test'), 'I03 requires a dedicated synthetic database.');
        $this->refreshSyntheticDatabase();
    }

    private function actor(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        return $user;
    }

    private function account(User $actor, string $code, string $amount): array
    {
        return app(TreasuryRegisterService::class)->open($actor, [
            'code' => $code, 'name' => 'Synthetic treasury '.$code, 'channel' => 'cash',
            'opening_on' => today()->toDateString(), 'opening_amount' => $amount,
            'opening_reference' => $code.'-OPEN', 'opening_evidence' => 'Independently counted synthetic opening cash',
        ]);
    }

    private function dispatchData(array $from, array $to, string $amount): array
    {
        return ['from_account_id' => $from['id'], 'to_account_id' => $to['id'],
            'amount' => $amount, 'reference' => 'I03-DISPATCH', 'dispatched_on' => today()->toDateString(),
            'evidence' => 'Synthetic independently witnessed cash dispatch'];
    }

    public function test_transfer_conserves_every_minor_unit_at_document_limit(): void
    {
        $sender = $this->actor();
        $receiver = $this->actor();
        $from = $this->account($sender, 'I03-FROM', '999999999999.99');
        $to = $this->account($sender, 'I03-TO', '0.00');
        $service = app(TreasuryTransferService::class);
        $register = app(TreasuryRegisterService::class);
        $data = $this->dispatchData($from, $to, '999999999999.98');
        $sent = $service->dispatch($sender, $data);
        $this->assertSame(99999999999998, $sent['in_transit_minor']);
        $this->assertSame(1, $register->inspect($sender, $from['id'])['provisional_recorded_balance_minor']);
        $this->assertSame(99999999999998, $register->inspect($sender, $from['id'])['outgoing_minor']);
        $this->assertSame(0, $register->inspect($sender, $to['id'])['provisional_recorded_balance_minor']);
        $receipt = ['amount' => '999999999999.97', 'reference' => 'I03-RECEIPT',
            'received_on' => today()->toDateString(), 'evidence' => 'Synthetic independently witnessed receipt'];
        try {
            $service->receive($receiver, $sent['id'], $receipt);
            $this->fail('A one-minor-unit mismatch must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
        $this->assertDatabaseCount('treasury_transfer_receipts', 0);
        $receipt['amount'] = '999999999999.98';
        $received = $service->receive($receiver, $sent['id'], $receipt);
        $this->assertSame('received', $received['status']);
        $this->assertSame(0, $received['in_transit_minor']);
        $this->assertSame($sent['id'], $service->dispatch($sender, $data)['id']);
        $this->assertSame($received['id'], $service->receive($receiver, $sent['id'], $receipt)['id']);
        $this->assertSame(99999999999998, $register->inspect($sender, $to['id'])['incoming_minor']);
        $this->assertSame(99999999999998, $register->inspect($sender, $to['id'])['provisional_recorded_balance_minor']);
        $this->assertSame([], $register->inspect($sender, $from['id'])['issues']);
        $this->assertSame([], $register->inspect($sender, $to['id'])['issues']);
        $this->assertDatabaseCount('treasury_postings', 2);
        $this->assertDatabaseCount('treasury_transfers', 1);
        $this->assertDatabaseCount('treasury_transfer_receipts', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_count_surplus_is_one_minor_unit_and_preserves_original_hash(): void
    {
        $counter = $this->actor();
        $reviewer = $this->actor();
        $account = $this->account($counter, 'I03-COUNT', '999999999999.98');
        $service = app(TreasuryCashCountService::class);
        $raw = DB::table('treasury_accounts')->find($account['id']);
        $hash = hash('sha256', json_encode([(array) $raw, []], JSON_THROW_ON_ERROR));
        $recorded = $service->record($counter, $account['id'], '999999999999.99', 'I03-COUNT-1', 'Synthetic physical count at limit');
        $count = $recorded['count'];
        $this->assertSame($hash, $count['ledger_hash']);
        $this->assertSame(99999999999998, (int) $count['recorded_minor']);
        $this->assertSame(1, (int) $count['variance_minor']);
        $this->assertSame('surplus', $recorded['variance_status']);
        $service->review($reviewer, $count['id'], true, 'Synthetic independent count review');
        $args = [$reviewer, $count['id'], 'I03-ADJUST', 'unattributed_cash_held',
            'Synthetic documented surplus is held pending attribution', 'Synthetic independent supporting evidence'];
        $adjusted = $service->adjust(...$args);
        $this->assertSame(1, (int) $adjusted['posting']['amount_minor']);
        $this->assertSame($count, (array) DB::table('treasury_cash_counts')->find($count['id']));
        $this->assertSame($adjusted['posting']['id'], $service->adjust(...$args)['posting']['id']);
        $inspection = app(TreasuryRegisterService::class)->inspect($reviewer, $account['id']);
        $this->assertSame(99999999999999, $inspection['provisional_recorded_balance_minor']);
        $this->assertSame(1, $inspection['unattributed_cash_held_minor']);
        $this->assertSame([], $inspection['issues']);
        $this->assertFalse($inspection['certified_balance']);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cancelled_audit_rolls_back_transfer_and_count(): void
    {
        $actor = $this->actor();
        $from = $this->account($actor, 'I03-AUDIT-A', '1.00');
        $to = $this->account($actor, 'I03-AUDIT-B', '0.00');
        AuditEvent::creating(static function (AuditEvent $event) {
            if (in_array($event->event, ['treasury.transfer_dispatched', 'treasury.cash_count_recorded'], true)) {
                return false;
            }
        });
        foreach ([
            fn () => app(TreasuryTransferService::class)->dispatch($actor, $this->dispatchData($from, $to, '0.01')),
            fn () => app(TreasuryCashCountService::class)->record($actor, $from['id'], '1.00', 'I03-FAILED-COUNT', 'Synthetic independently witnessed count'),
        ] as $action) {
            try {
                $action();
                $this->fail('Cancelled audit must reject the financial mutation.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('audit', strtolower($error->getMessage()));
            }
        }
        $this->assertDatabaseCount('treasury_transfers', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('treasury_cash_counts', 0);
        $this->assertSame(100, app(TreasuryRegisterService::class)->inspect($actor, $from['id'])['provisional_recorded_balance_minor']);
    }

    public function test_exact_running_and_gross_domains_reject_unrepresentable_values(): void
    {
        $this->assertSame(['balance' => PHP_INT_MAX, 'incoming' => 1, 'outgoing' => 0],
            TreasuryMoney::accumulate(PHP_INT_MAX - 1, 0, 0, '1'));
        $this->assertSame(PHP_INT_MAX, TreasuryMoney::variance('0', (string) -PHP_INT_MAX));
        foreach ([
            fn () => TreasuryMoney::accumulate(PHP_INT_MAX, 0, 0, 1),
            fn () => TreasuryMoney::accumulate(0, PHP_INT_MAX, 0, 1),
            fn () => TreasuryMoney::accumulate(0, 0, PHP_INT_MAX, -1),
            fn () => TreasuryMoney::variance(1, -PHP_INT_MAX),
            fn () => TreasuryMoney::document('100000000000000'),
            fn () => TreasuryMoney::signed('9223372036854775808'),
            fn () => TreasuryMoney::signed(PHP_INT_MIN),
            fn () => TreasuryMoney::signed(1.0),
        ] as $action) {
            try {
                $action();
                $this->fail('Invalid or overflowing monetary value accepted.');
            } catch (\InvalidArgumentException|\OverflowException $error) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
