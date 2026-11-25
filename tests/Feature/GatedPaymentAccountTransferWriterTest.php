<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Models\User;
use App\Models\AuditEvent;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryTransferService;
use App\Services\TreasuryTransferEvidence;
use App\Services\GatedCashReceiptWriter;
use App\Services\PaymentService;
use App\Models\Subscriber;
use App\Models\Square;
use App\Models\Payment;
use App\Models\PaymentAccountTransfer;
use App\Services\GatedPaymentAccountTransferWriter;
use App\Services\AllocationSnapshotIntegrity;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedPaymentAccountTransferWriterTest extends TestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || ! (
            (DB::getDriverName() === 'sqlite' && DB::getDatabaseName() === ':memory:')
            || (DB::getDriverName() === 'mysql' && DB::getDatabaseName() === 'water_test')
        )) {
            $this->markTestSkipped('Requires disposable isolated ledger evidence database.');
        }
        $this->travelTo(\Carbon\Carbon::instance($this->nowAt()));
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        DB::table('financial_scopes')->insert([
            'scope_key' => 'water', 'currency' => 'YER', 'timezone' => 'Asia/Aden',
            'mode' => 'ACTIVE', 'calendar_version' => 1, 'fence_epoch' => 5,
        ]);
        DB::table('financial_periods')->insert([
            'scope_key' => 'water', 'month' => '2026-11', 'start_on' => '2026-11-01',
            'next_on' => '2026-12-01', 'state' => 'OPEN', 'version' => 2,
        ]);
    }

    private function nowAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-25T08:30:00.123456Z');
    }

    private function gate(callable $writer): mixed
    {
        return app(FinancialWriteGate::class)->withinOpenPeriod('water', '2026-11-25', $this->nowAt(), $writer);
    }

    private function actor(): User
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $actor;
    }

    private function account(User $actor, string $code, string $amount): array
    {
        return app(TreasuryRegisterService::class)->open($actor, [
            'code' => $code, 'name' => 'Synthetic treasury '.$code, 'channel' => 'cash',
            'opening_on' => '2026-11-25', 'opening_amount' => $amount,
            'opening_reference' => $code.'-OPEN',
            'opening_evidence' => 'Independently counted synthetic opening cash',
        ]);
    }

    private const CORRECTION = '33333333-3333-4333-8333-333333333333';
    private const REASON = 'Synthetic receipt assigned to wrong subscriber';
    private const EVIDENCE = 'Independent evidence of the correct subscriber';

    private function subscriber(string $name): Subscriber
    {
        return Square::create(['name' => 'Synthetic '.$name.' square'])->subscribers()
            ->create(['name' => 'Synthetic '.$name, 'status' => 'active']);
    }

    private function fixture(bool $registered = true, bool $approved = true): array
    {
        $collector = $this->actor();
        $reviewer = $this->actor();
        // Destination has the lower ID: the writer must lock by ID, not direction.
        $to = $this->subscriber('destination');
        $from = $this->subscriber('origin');
        $sessions = app(CashSessionService::class);
        $session = $sessions->open($collector);
        $payment = $registered
            ? app(GatedCashReceiptWriter::class)->collect($collector, $from->id, '10.25',
                self::UUID, $this->nowAt(), (int) $session->id)['payment']
            : app(PaymentService::class)->collectPayment($from, '10.25', userId: $collector->id,
                paymentDate: '2026-11-25', syncUuid: self::UUID, requestedCashSessionId: (int) $session->id);
        // Non-zero destination balance proves the transfer preserves existing money.
        app(GatedCashReceiptWriter::class)->collect($reviewer, $to->id, '4.11', self::OTHER, $this->nowAt());
        if ($approved) {
            $session = $sessions->decide($sessions->close($session->fresh(), '10.25'), true, $reviewer);
        }
        return [$collector, $reviewer, $from, $to, $payment, $session];
    }

    private function post(User $actor, Payment $payment, Subscriber $to, string $uuid = self::CORRECTION,
        string $reason = self::REASON, string $evidence = self::EVIDENCE): array
    {
        return app(GatedPaymentAccountTransferWriter::class)->transfer($actor, (int) $payment->id,
            (int) $to->id, $reason, $evidence, $uuid, $this->nowAt());
    }

    private function rejects(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejection.');
        } catch (DomainException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
    }

    private function allocation(Subscriber $subscriber): array
    {
        $revision = DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)
            ->orderByDesc('revision')->first();
        $this->assertNotNull($revision);
        $this->assertTrue(app(AllocationSnapshotIntegrity::class)->valid($revision));
        return ['revision' => (array) $revision,
            'rows' => DB::table('payment_allocations')->where('revision_id', $revision->id)
                ->orderBy('position')->get()->map(fn ($row) => (array) $row)->all()];
    }

    public function test_actual_transfer_conserves_both_balances_and_preserves_closed_cash_receipt(): void
    {
        [, $reviewer, $from, $to, $payment, $session] = $this->fixture();
        $receipt = $payment->fresh()->getRawOriginal();
        $till = $session->fresh()->getRawOriginal();
        $original = (array) DB::table('financial_posting_events')->where('operation_uuid', self::UUID)->sole();
        $this->assertSame(1025, $from->fresh()->getAccountBalanceMinor());
        $this->assertSame(411, $to->fresh()->getAccountBalanceMinor());
        $result = $this->post($reviewer, $payment, $to);
        $this->assertTrue($result['registry_created']);
        $this->assertSame('10.25', $result['transfer']->amount);
        $this->assertSame(0, $from->fresh()->getAccountBalanceMinor());
        $this->assertSame(1436, $to->fresh()->getAccountBalanceMinor());
        $this->assertSame($receipt, $payment->fresh()->getRawOriginal());
        $this->assertSame($till, $session->fresh()->getRawOriginal());
        $this->assertSame(1025, app(CashSessionService::class)->expectedAmountMinor($session->fresh()));
        $this->assertSame($original, (array) DB::table('financial_posting_events')->where('operation_uuid', self::UUID)->sole());
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::CORRECTION,
            'source_type' => 'ledger_transaction', 'source_id' => (string) $result['out_transaction_id'], 'amount_minor' => -1025]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::CORRECTION,
            'source_type' => 'ledger_transaction', 'source_id' => (string) $result['in_transaction_id'], 'amount_minor' => 1025]);
        $this->assertSame(0, (int) $this->allocation($from)['revision']['available_minor']);
        $allocation = $this->allocation($to);
        $this->assertSame(1436, (int) $allocation['revision']['unallocated_minor']);
        $incoming = array_values(array_filter($allocation['rows'], fn ($row) => $row['account_transfer_id'] !== null));
        $this->assertCount(1, $incoming);
        $this->assertSame((int) $result['transfer']->id, (int) $incoming[0]['account_transfer_id']);
        $this->assertSame(1025, (int) $incoming[0]['amount_minor']);
        $again = $this->post($reviewer, $payment, $to);
        $this->assertFalse($again['registry_created']);
        $this->assertSame($result['out_transaction_id'], $again['out_transaction_id']);
        $this->assertSame($result['in_transaction_id'], $again['in_transaction_id']);
        $this->assertDatabaseCount('payment_account_transfers', 1);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('transactions', 4);
        $this->assertDatabaseCount('financial_posting_operations', 3);
        $this->assertDatabaseCount('financial_posting_events', 4);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertSame(1, AuditEvent::query()->where('event', 'payment.account_transferred')->count());
        $this->gate(function (array $context): void {
            $verified = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(4, $verified['registered_ledger_legs_verified']);
            $this->assertFalse($verified['source_coverage_verified']);
        });
    }

    public function test_actor_destination_and_exact_replay_conflicts_are_rejected(): void
    {
        [$collector, $reviewer, $from, $to, $payment] = $this->fixture();
        try {
            $this->post($collector, $payment, $to);
            $this->fail('Self approval accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->rejects('gated_account_transfer_destination', fn () => $this->post($reviewer, $payment, $from));
        $this->post($reviewer, $payment, $to);
        $this->rejects('gated_account_transfer_operation_conflict',
            fn () => $this->post($reviewer, $payment, $to, '44444444-4444-4444-8444-444444444444'));
        $this->rejects('gated_account_transfer_operation_conflict',
            fn () => $this->post($reviewer, $payment, $to, evidence: 'Changed independent subscriber evidence'));
        $this->rejects('gated_account_transfer_operation_conflict',
            fn () => $this->post($reviewer, $payment, $this->subscriber('third')));
        $this->assertSame(0, $from->fresh()->getAccountBalanceMinor());
        $this->assertSame(1436, $to->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payment_account_transfers', 1);
        $this->assertDatabaseCount('transactions', 4);
    }

    public function test_unregistered_original_is_not_adopted(): void
    {
        [, $reviewer, $from, $to, $payment] = $this->fixture(registered: false);
        $this->rejects('gated_account_transfer_prerequisite_unregistered',
            fn () => $this->post($reviewer, $payment, $to));
        $this->assertSame(1025, $from->fresh()->getAccountBalanceMinor());
        $this->assertSame(411, $to->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payment_account_transfers', 0);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_unapproved_till_closed_period_and_frozen_scope_reject_transfer(): void
    {
        [, $reviewer, $from, $to, $payment, $session] = $this->fixture(approved: false);
        $post = fn () => $this->post($reviewer, $payment, $to);
        $this->rejects('gated_account_transfer_till_unapproved', $post);
        $sessions = app(CashSessionService::class);
        $closed = $sessions->close($session->fresh(), '10.25');
        $this->rejects('gated_account_transfer_till_unapproved', $post);
        $sessions->decide($closed, true, $reviewer);
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $post);
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::CORRECTION]);
        $this->rejects('financial_scope_not_active', $post);
        $this->assertSame(1025, $from->fresh()->getAccountBalanceMinor());
        $this->assertSame(411, $to->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payment_account_transfers', 0);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);
    }

    public function test_registry_failure_restores_both_accounts_allocation_histories_and_audit(): void
    {
        [, $reviewer, $from, $to, $payment, $session] = $this->fixture();
        $beforeFrom = $this->allocation($from);
        $beforeTo = $this->allocation($to);
        $receipt = $payment->fresh()->getRawOriginal();
        $till = $session->fresh()->getRawOriginal();
        $auditCount = AuditEvent::query()->count();
        $revisionCount = DB::table('payment_allocation_revisions')->count();
        $rowCount = DB::table('payment_allocations')->count();
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'";
        DB::unprepared($trigger);
        try {
            $this->post($reviewer, $payment, $to);
            $this->fail('Registry insert should fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_registry_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_registry_failure');
        }
        $this->assertSame(1025, $from->fresh()->getAccountBalanceMinor());
        $this->assertSame(411, $to->fresh()->getAccountBalanceMinor());
        $this->assertSame($beforeFrom, $this->allocation($from));
        $this->assertSame($beforeTo, $this->allocation($to));
        $this->assertSame($receipt, $payment->fresh()->getRawOriginal());
        $this->assertSame($till, $session->fresh()->getRawOriginal());
        $this->assertSame($auditCount, AuditEvent::query()->count());
        $this->assertDatabaseCount('payment_allocation_revisions', $revisionCount);
        $this->assertDatabaseCount('payment_allocations', $rowCount);
        $this->assertDatabaseCount('payment_account_transfers', 0);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('financial_posting_operations', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertTrue($this->post($reviewer, $payment, $to)['registry_created']);
        $this->assertSame(0, $from->fresh()->getAccountBalanceMinor());
        $this->assertSame(1436, $to->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payment_account_transfers', 1);
        $this->assertDatabaseCount('transactions', 4);
        $this->assertDatabaseCount('financial_posting_events', 4);
        $this->assertDatabaseCount('treasury_postings', 0);
    }
}
