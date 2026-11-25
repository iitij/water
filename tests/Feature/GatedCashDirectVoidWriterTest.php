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
use App\Models\Setting;
use App\Services\GatedCashDirectVoidWriter;
use App\Services\AllocationSnapshotIntegrity;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedCashDirectVoidWriterTest extends TestCase
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

    private const REASON = 'Synthetic incorrect receipt cancellation';

    private function fixture(bool $registered = true, bool $withTill = false): array
    {
        $collector = $this->actor();
        $reviewer = $this->actor();
        $subscriber = Square::create(['name' => 'Synthetic direct void square'])->subscribers()
            ->create(['name' => 'Synthetic direct void account', 'status' => 'active']);
        $session = $withTill ? app(CashSessionService::class)->open($collector) : null;
        $payment = $registered
            ? app(GatedCashReceiptWriter::class)->collect($collector, $subscriber->id, '10.25',
                self::UUID, $this->nowAt(), $session?->id)['payment']
            : app(PaymentService::class)->collectPayment($subscriber, '10.25', userId: $collector->id,
                paymentDate: '2026-11-25', syncUuid: self::UUID);
        Setting::set('void_approval_threshold', '10.26');
        return [$collector, $reviewer, $subscriber, $payment, $session];
    }

    private function post(User $actor, Payment $payment, string $uuid = self::OTHER,
        string $reason = self::REASON): array
    {
        return app(GatedCashDirectVoidWriter::class)->void($actor, (int) $payment->id,
            $reason, $uuid, $this->nowAt());
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

    public function test_one_minor_below_threshold_voids_once_and_replays_after_policy_change(): void
    {
        [, $reviewer, $subscriber, $payment, $session] = $this->fixture(withTill: true);
        $original = DB::table('financial_posting_events')->where('operation_uuid', self::UUID)->sole();
        $this->assertSame(1025, app(CashSessionService::class)->expectedAmountMinor($session->fresh()));
        $result = $this->post($reviewer, $payment);
        $this->assertTrue($result['registry_created']);
        $this->assertTrue($result['payment']->isVoided());
        $this->assertSame($payment->receipt_number, $result['payment']->receipt_number);
        $this->assertSame('10.25', $result['payment']->amount);
        $this->assertSame(0, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame(0, app(CashSessionService::class)->expectedAmountMinor($session->fresh()));
        $this->assertSame((array) $original, (array) DB::table('financial_posting_events')->where('operation_uuid', self::UUID)->sole());
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::OTHER,
            'source_type' => 'ledger_transaction', 'source_id' => (string) $result['transaction_id'], 'amount_minor' => -1025]);
        Setting::set('void_approval_threshold', '1.00');
        $again = $this->post($reviewer, $payment);
        $this->assertFalse($again['registry_created']);
        $this->assertSame($result['transaction_id'], $again['transaction_id']);
        $this->rejects('gated_direct_void_operation_conflict',
            fn () => $this->post($reviewer, $payment, reason: 'Different cancellation reason'));
        $this->rejects('gated_direct_void_operation_conflict',
            fn () => $this->post($reviewer, $payment, '33333333-3333-4333-8333-333333333333'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertSame(1, AuditEvent::query()->where('event', 'payment.voided')->count());
        $this->assertSame(1, AuditEvent::query()->where('event', 'financial.direct_void_registered')->count());
        $this->gate(function (array $context): void {
            $verified = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(2, $verified['registered_ledger_legs_verified']);
            $this->assertFalse($verified['source_coverage_verified']);
        });
    }

    public function test_equal_above_zero_missing_and_invalid_thresholds_fail_closed(): void
    {
        [, $reviewer, $subscriber, $payment] = $this->fixture();
        foreach (['10.25', '10.24'] as $threshold) {
            Setting::set('void_approval_threshold', $threshold);
            $this->rejects('gated_direct_void_requires_approval', fn () => $this->post($reviewer, $payment));
        }
        Setting::set('void_approval_threshold', '0');
        $this->rejects('gated_direct_void_threshold_required', fn () => $this->post($reviewer, $payment));
        DB::table('settings')->where('key', 'void_approval_threshold')->delete();
        $this->rejects('gated_direct_void_threshold_required', fn () => $this->post($reviewer, $payment));
        Setting::set('void_approval_threshold', 'invalid');
        try {
            $this->post($reviewer, $payment);
            $this->fail('Invalid policy accepted.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('void_approval_threshold', $error->errors());
        }
        $this->assertFalse($payment->fresh()->isVoided());
        $this->assertFalse($payment->fresh()->hasPendingVoidRequest());
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_pending_request_cannot_be_bypassed_after_threshold_increases(): void
    {
        [$collector, $reviewer, $subscriber, $payment] = $this->fixture();
        Setting::set('void_approval_threshold', '10.25');
        $request = app(PaymentService::class)->requestVoid($payment, self::REASON, $collector->id);
        $this->assertTrue($request['pending']);
        Setting::set('void_approval_threshold', '100.00');
        $this->rejects('gated_direct_void_pending_approval', fn () => $this->post($reviewer, $payment));
        $this->assertTrue($payment->fresh()->hasPendingVoidRequest());
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_unregistered_original_is_rejected(): void
    {
        [, $reviewer, $subscriber, $payment] = $this->fixture(registered: false);
        $this->rejects('gated_direct_void_prerequisite_unregistered', fn () => $this->post($reviewer, $payment));
        $this->assertFalse($payment->fresh()->isVoided());
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_events', 0);
    }

    public function test_closed_period_frozen_scope_and_closed_till_are_protected(): void
    {
        [, $reviewer, $subscriber, $payment, $session] = $this->fixture(withTill: true);
        $post = fn () => $this->post($reviewer, $payment);
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $post);
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::OTHER]);
        $this->rejects('financial_scope_not_active', $post);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => null]);
        $closed = app(CashSessionService::class)->close($session->fresh(), '10.25');
        try {
            $post();
            $this->fail('Closed till void accepted.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('cash_session', $error->errors());
        }
        $this->assertFalse($payment->fresh()->isVoided());
        $this->assertSame('10.25', $closed->fresh()->counted_amount);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_registry_failure_restores_receipt_and_allocations_then_retries(): void
    {
        [, $reviewer, $subscriber, $payment, $session] = $this->fixture(withTill: true);
        $before = $this->allocation($subscriber);
        $auditCount = AuditEvent::query()->count();
        $revisionCount = DB::table('payment_allocation_revisions')->count();
        $rowCount = DB::table('payment_allocations')->count();
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'";
        DB::unprepared($trigger);
        try {
            $this->post($reviewer, $payment);
            $this->fail('Registry insert should fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_registry_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_registry_failure');
        }
        $this->assertFalse($payment->fresh()->isVoided());
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame(1025, app(CashSessionService::class)->expectedAmountMinor($session->fresh()));
        $this->assertSame($before, $this->allocation($subscriber));
        $this->assertSame($auditCount, AuditEvent::query()->count());
        $this->assertDatabaseCount('payment_allocation_revisions', $revisionCount);
        $this->assertDatabaseCount('payment_allocations', $rowCount);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_operations', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
        $this->assertTrue($this->post($reviewer, $payment)['registry_created']);
        $this->assertSame(0, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame(0, app(CashSessionService::class)->expectedAmountMinor($session->fresh()));
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);
    }
}
