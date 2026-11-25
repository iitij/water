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
use App\Services\GatedCashRefundWriter;
use App\Services\GatedTreasuryOpeningFundingWriter;
use App\Services\PaymentRefundService;
use App\Services\AllocationSnapshotIntegrity;
use App\Services\PaymentService;
use App\Models\Subscriber;
use App\Models\Square;
use App\Models\Payment;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedCashRefundWriterTest extends TestCase
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

    private const REFUND = '33333333-3333-4333-8333-333333333333';
    private const NEXT = '44444444-4444-4444-8444-444444444444';

    private function fixture(bool $registeredReceipt = true, bool $registeredOpening = true): array
    {
        $collector = $this->actor();
        $reviewer = $this->actor();
        $subscriber = Square::create(['name' => 'Synthetic refund square'])->subscribers()
            ->create(['name' => 'Synthetic refund account', 'status' => 'active']);
        $data = ['code' => 'QA-GATED-REFUND', 'name' => 'Synthetic refund cash', 'channel' => 'cash',
            'opening_on' => '2026-11-25', 'opening_amount' => '100.00',
            'opening_reference' => 'QA-REFUND-OPEN', 'opening_evidence' => 'Independent physical opening count'];
        $account = $registeredOpening
            ? app(GatedTreasuryOpeningFundingWriter::class)->open($reviewer, $data, self::UUID, $this->nowAt())['account']
            : app(TreasuryRegisterService::class)->open($reviewer, $data);
        $payment = $registeredReceipt
            ? app(GatedCashReceiptWriter::class)->collect($collector, $subscriber->id, '10.25', self::OTHER, $this->nowAt())['payment']
            : app(PaymentService::class)->collectPayment($subscriber, '10.25', userId: $collector->id,
                paymentDate: '2026-11-25', syncUuid: self::OTHER);
        return [$collector, $reviewer, $subscriber, $payment, $account];
    }

    private function post(User $actor, Payment $payment, array $account, string $amount = '2.13',
        string $uuid = self::REFUND, string $reference = 'QA-REFUND-PROOF',
        string $evidence = 'Independent evidence of actual physical disbursement'): array
    {
        return app(GatedCashRefundWriter::class)->record($actor, (int) $payment->id, (int) $account['id'],
            $amount, $reference, 'Refund of excess subscriber credit', $evidence, $uuid, $this->nowAt());
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
        return ['id' => (int) $revision->id, 'hash' => $revision->allocation_hash,
            'available' => (int) $revision->available_minor, 'allocated' => (int) $revision->allocated_minor,
            'unallocated' => (int) $revision->unallocated_minor,
            'rows' => DB::table('payment_allocations')->where('revision_id', $revision->id)
                ->orderBy('position')->get()->map(fn ($row) => (array) $row)->all()];
    }

    public function test_actual_refund_records_both_effects_and_replays_once(): void
    {
        [, $reviewer, $subscriber, $payment, $account] = $this->fixture();
        $first = $this->post($reviewer, $payment, $account);
        $this->assertTrue($first['registry_created']);
        $this->assertSame('2.13', $first['refund']->amount);
        $this->assertSame(812, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::REFUND,
            'source_type' => 'ledger_transaction', 'source_id' => (string) $first['transaction_id'], 'amount_minor' => -213]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::REFUND,
            'source_type' => 'treasury_posting', 'source_id' => (string) $first['posting_id'], 'amount_minor' => -213]);
        $again = $this->post($reviewer, $payment, $account);
        $this->assertFalse($again['registry_created']);
        $this->assertSame($first['refund']->id, $again['refund']->id);
        $this->assertSame($first['posting_id'], $again['posting_id']);
        $this->assertSame($first['transaction_id'], $again['transaction_id']);
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('financial_posting_operations', 3);
        $this->assertDatabaseCount('financial_posting_events', 4);
        $this->assertSame(1, AuditEvent::query()->where('event', 'payment.refunded')->count());
        $this->assertSame(1, AuditEvent::query()->where('event', 'treasury.posted')->count());
        $this->assertSame(812, $this->allocation($subscriber)['unallocated']);
        $this->gate(function (array $context): void {
            $result = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(2, $result['registered_ledger_legs_verified']);
            $this->assertSame(1, $result['registered_treasury_legs_verified']);
            $this->assertSame(1, $result['registered_opening_legs_verified']);
            $this->assertFalse($result['source_coverage_verified']);
        });
    }

    public function test_legacy_receipt_and_opening_are_not_adopted(): void
    {
        [, $reviewer, , $payment, $account] = $this->fixture(false, false);
        $this->rejects('gated_refund_prerequisite_unregistered',
            fn () => $this->post($reviewer, $payment, $account));
        $subscriber = Square::create(['name' => 'Other refund square'])->subscribers()
            ->create(['name' => 'Other refund account', 'status' => 'active']);
        $registered = app(GatedCashReceiptWriter::class)->collect($this->actor(), $subscriber->id,
            '10.25', self::NEXT, $this->nowAt())['payment'];
        $this->rejects('gated_refund_prerequisite_unregistered',
            fn () => $this->post($reviewer, $registered, $account));
        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_conflicts_limits_and_independent_actor(): void
    {
        [$collector, $reviewer, $subscriber, $payment, $account] = $this->fixture();
        try {
            $this->post($collector, $payment, $account);
            $this->fail('Self refund accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->post($reviewer, $payment, $account);
        $this->rejects('gated_refund_operation_conflict',
            fn () => $this->post($reviewer, $payment, $account, uuid: self::NEXT));
        $this->rejects('gated_refund_operation_conflict',
            fn () => $this->post($reviewer, $payment, $account, evidence: 'Changed physical disbursement evidence'));
        try {
            $this->post($reviewer, $payment, $account, amount: '8.13', uuid: self::NEXT, reference: 'QA-EXCESS');
            $this->fail('Excess refund accepted.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('amount', $error->errors());
        }
        $this->assertSame(812, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_closed_and_frozen_reject_before_refund(): void
    {
        [, $reviewer, $subscriber, $payment, $account] = $this->fixture();
        $post = fn () => $this->post($reviewer, $payment, $account);
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $post);
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::NEXT]);
        $this->rejects('financial_scope_not_active', $post);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_events', 2);
    }

    public function test_registry_failure_restores_both_ledgers_allocations_and_audit(): void
    {
        [, $reviewer, $subscriber, $payment, $account] = $this->fixture();
        $before = $this->allocation($subscriber);
        $auditCount = AuditEvent::query()->count();
        $revisionCount = DB::table('payment_allocation_revisions')->count();
        $rowCount = DB::table('payment_allocations')->count();
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'";
        DB::unprepared($trigger);
        try {
            $this->post($reviewer, $payment, $account);
            $this->fail('Registry insert should fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_registry_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_registry_failure');
        }
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame($before, $this->allocation($subscriber));
        $this->assertSame($auditCount, AuditEvent::query()->count());
        $this->assertDatabaseCount('payment_allocation_revisions', $revisionCount);
        $this->assertDatabaseCount('payment_allocations', $rowCount);
        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_operations', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertTrue($this->post($reviewer, $payment, $account)['registry_created']);
        $this->assertSame(812, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame(812, $this->allocation($subscriber)['unallocated']);
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_events', 4);
    }
}
