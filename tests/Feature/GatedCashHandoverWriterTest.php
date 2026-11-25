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
use App\Models\CashSession;
use App\Services\GatedCashHandoverWriter;
use App\Services\GatedTreasuryOpeningFundingWriter;
use App\Services\CashHandoverService;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedCashHandoverWriterTest extends TestCase
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

    private const FUND = '33333333-3333-4333-8333-333333333333';
    private const FIRST = '44444444-4444-4444-8444-444444444444';
    private const SECOND = '55555555-5555-4555-8555-555555555555';
    private const EVIDENCE = 'Independent verified physical cash handover';

    private function fixture(bool $registered = true, bool $approved = true, bool $funded = false,
        bool $registeredOpening = true): array
    {
        $collector = $this->actor();
        $receiver = $this->actor();
        $subscriber = Subscriber::create(['name' => 'Synthetic handover account',
            'square_id' => Square::create(['name' => 'Synthetic handover square'])->id, 'status' => 'active']);
        $data = ['code' => 'QA-HANDOVER', 'name' => 'Synthetic handover treasury', 'channel' => 'cash',
            'opening_on' => '2026-11-25', 'opening_amount' => '100.00',
            'opening_reference' => 'QA-HANDOVER-OPEN', 'opening_evidence' => 'Independent opening count evidence'];
        $writer = app(GatedTreasuryOpeningFundingWriter::class);
        $account = $registeredOpening ? $writer->open($receiver, $data, self::UUID, $this->nowAt())['account']
            : app(TreasuryRegisterService::class)->open($receiver, $data);
        $sessions = app(CashSessionService::class);
        $session = $funded ? $writer->fund($collector, $receiver, (int) $account['id'], '5.00',
            'QA-HANDOVER-FUND', 'Independent physical funding evidence', self::FUND, $this->nowAt())['session']
            : $sessions->open($collector);
        $payment = $registered ? app(GatedCashReceiptWriter::class)->collect($collector, (int) $subscriber->id,
            '20.25', self::OTHER, $this->nowAt(), (int) $session->id)['payment']
            : app(PaymentService::class)->collectPayment($subscriber, '20.25', userId: $collector->id,
                paymentDate: '2026-11-25', syncUuid: self::OTHER, requestedCashSessionId: (int) $session->id);
        if ($approved) {
            $session = $sessions->decide($sessions->close($session->fresh(), $funded ? '25.25' : '20.25'), true, $receiver);
        }
        return [$collector, $receiver, $subscriber, $account, $session->fresh(), $payment->fresh()];
    }

    private function post(User $actor, CashSession $session, array $account, string $amount = '10.13',
        string $uuid = self::FIRST, string $reference = 'QA-HANDOVER-01', string $evidence = self::EVIDENCE): array
    {
        return app(GatedCashHandoverWriter::class)->receive($actor, (int) $session->id, (int) $account['id'],
            $amount, $reference, $evidence, $uuid, $this->nowAt());
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

    public function test_partial_full_and_exact_replay_preserve_subscriber_and_original_till(): void
    {
        [, $receiver, $subscriber, $account, $session, $payment] = $this->fixture();
        $receipt = $payment->getRawOriginal();
        $till = $session->getRawOriginal();
        $first = $this->post($receiver, $session, $account);
        $this->assertTrue($first['registry_created']);
        $this->assertSame('partial', $first['summary']['status']);
        $this->assertSame('10.12', $first['summary']['remaining_amount']);
        $second = $this->post($receiver, $session, $account, '10.12', self::SECOND, 'QA-HANDOVER-02');
        $this->assertTrue($second['registry_created']);
        $this->assertSame('complete', $second['summary']['status']);
        $this->assertSame('0.00', $second['summary']['remaining_amount']);
        $again = $this->post($receiver, $session, $account);
        $this->assertFalse($again['registry_created']);
        $this->assertSame($first['posting_id'], $again['posting_id']);
        $this->assertSame(2025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame($receipt, $payment->fresh()->getRawOriginal());
        $this->assertSame($till, $session->fresh()->getRawOriginal());
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::FIRST,
            'source_id' => (string) $first['posting_id'], 'amount_minor' => 1013]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::SECOND,
            'source_id' => (string) $second['posting_id'], 'amount_minor' => 1012]);
        $this->assertDatabaseCount('cash_handovers', 2);
        $this->assertDatabaseCount('treasury_postings', 2);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_events', 4);
        $this->assertSame(2, AuditEvent::query()->where('event', 'cash_handover.received')->count());
        $this->gate(function (array $context): void {
            $v = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $v['registered_ledger_legs_verified']);
            $this->assertSame(2, $v['registered_treasury_legs_verified']);
            $this->assertSame(1, $v['registered_opening_legs_verified']);
            $this->assertFalse($v['source_coverage_verified']);
        });
    }

    public function test_registered_funding_returns_with_collections_without_double_credit(): void
    {
        [, $receiver, $subscriber, $account, $session] = $this->fixture(funded: true);
        $result = $this->post($receiver, $session, $account, '25.25');
        $this->assertTrue($result['registry_created']);
        $this->assertSame('complete', $result['summary']['status']);
        $this->assertSame(2025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame(2025, (int) DB::table('treasury_postings')->sum('amount_minor'));
        $this->assertDatabaseCount('treasury_postings', 2);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_events', 4);
    }

    public function test_legacy_receipt_or_prior_handover_cannot_be_adopted(): void
    {
        [, $receiver, , $account, $session] = $this->fixture(registered: false);
        $this->rejects('gated_handover_prerequisite_unregistered', fn () => $this->post($receiver, $session, $account));
        $this->assertDatabaseCount('cash_handovers', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
    }

    public function test_unposted_prior_handover_blocks_new_posting(): void
    {
        [, $receiver, , $account, $session] = $this->fixture();
        app(CashHandoverService::class)->record($session, $receiver, '1.00', 'LEGACY-HANDOVER',
            '2026-11-25', self::EVIDENCE);
        $this->rejects('gated_handover_prior_unregistered', fn () => $this->post($receiver, $session, $account));
        $this->assertDatabaseCount('cash_handovers', 1);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_events', 2);
    }

    public function test_excess_cash_self_receipt_and_conflicting_uuid_are_rejected(): void
    {
        [$collector, $receiver, , $account, $session] = $this->fixture();
        try {
            $this->post($collector, $session, $account);
            $this->fail('Self receipt accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->rejects('gated_handover_exceeds_counted',
            fn () => $this->post($receiver, $session, $account, '20.26'));
        $this->post($receiver, $session, $account);
        $this->rejects('gated_handover_operation_conflict',
            fn () => $this->post($receiver, $session, $account, uuid: self::SECOND));
        $this->rejects('gated_handover_exceeds_counted',
            fn () => $this->post($receiver, $session, $account, '10.13', self::SECOND, 'QA-HANDOVER-02'));
        $this->assertDatabaseCount('cash_handovers', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
    }

    public function test_unapproved_till_and_closed_frozen_periods_reject_handover(): void
    {
        [, $receiver, , $account, $session] = $this->fixture(approved: false);
        $this->rejects('gated_handover_session_unverified', fn () => $this->post($receiver, $session, $account));
        $sessions = app(CashSessionService::class);
        $session = $sessions->decide($sessions->close($session, '20.25'), true, $receiver);
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', fn () => $this->post($receiver, $session, $account));
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::FIRST]);
        $this->rejects('financial_scope_not_active', fn () => $this->post($receiver, $session, $account));
        $this->assertDatabaseCount('cash_handovers', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
    }

    public function test_unregistered_opening_is_rejected(): void
    {
        [, $receiver, , $account, $session] = $this->fixture(registeredOpening: false);
        $this->rejects('gated_handover_prerequisite_unregistered', fn () => $this->post($receiver, $session, $account));
        $this->assertDatabaseCount('cash_handovers', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
    }

    public function test_registry_failure_restores_handover_and_treasury_then_retries(): void
    {
        [, $receiver, $subscriber, $account, $session, $payment] = $this->fixture();
        $audit = AuditEvent::query()->count();
        $before = app(CashHandoverService::class)->summary($session);
        $till = $session->getRawOriginal();
        $receipt = $payment->getRawOriginal();
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'";
        DB::unprepared($trigger);
        try {
            $this->post($receiver, $session, $account);
            $this->fail('Registry insert should fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_registry_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_registry_failure');
        }
        $this->assertSame($before, app(CashHandoverService::class)->summary($session->fresh()));
        $this->assertSame($till, $session->fresh()->getRawOriginal());
        $this->assertSame($receipt, $payment->fresh()->getRawOriginal());
        $this->assertSame($audit, AuditEvent::query()->count());
        $this->assertSame(2025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('cash_handovers', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_operations', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertTrue($this->post($receiver, $session, $account)['registry_created']);
        $this->assertDatabaseCount('cash_handovers', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_events', 3);
        $this->assertSame(2025, $subscriber->fresh()->getAccountBalanceMinor());
    }
}
