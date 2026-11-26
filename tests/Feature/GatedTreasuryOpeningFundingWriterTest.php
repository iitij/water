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
use App\Services\GatedTreasuryOpeningFundingWriter;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedTreasuryOpeningFundingWriterTest extends TestCase
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

    private function data(string $code = 'QA-GATED-OPEN'): array
    {
        return ['code' => $code, 'name' => 'Synthetic gated cash', 'channel' => 'cash',
            'opening_on' => '2026-11-25', 'opening_amount' => '100.25',
            'opening_reference' => $code.'-REF', 'opening_evidence' => 'Independent physical opening count'];
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

    public function test_opening_and_funding_are_registered_atomically_and_replay_exactly(): void
    {
        $issuer = $this->actor();
        $collector = $this->actor();
        $writer = app(GatedTreasuryOpeningFundingWriter::class);
        $opened = $writer->open($issuer, $this->data(), self::UUID, $this->nowAt());
        $this->assertTrue($opened['registry_created']);
        $account = $opened['account'];
        $again = $writer->open($issuer, $this->data(), self::UUID, $this->nowAt());
        $this->assertFalse($again['registry_created']);
        $this->assertSame($account['id'], $again['account']['id']);
        $fund = fn () => $writer->fund($collector, $issuer, $account['id'], '10.25',
            'QA-FUNDED-REF', 'Independent physical funding receipt', self::OTHER, $this->nowAt());
        $funded = $fund();
        $this->assertTrue($funded['registry_created']);
        $this->assertSame('10.25', $funded['session']->opening_amount);
        $again = $fund();
        $this->assertFalse($again['registry_created']);
        $this->assertSame($funded['posting_id'], $again['posting_id']);
        $this->assertSame($funded['session']->id, $again['session']->id);
        $this->assertDatabaseCount('treasury_accounts', 1);
        $this->assertDatabaseCount('cash_sessions', 1);
        $this->assertDatabaseCount('cash_session_fundings', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertDatabaseCount('financial_posting_operations', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->gate(function (array $context): void {
            $result = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $result['registered_opening_legs_verified']);
            $this->assertSame(1, $result['registered_treasury_legs_verified']);
            $this->assertFalse($result['source_coverage_verified']);
        });
    }

    public function test_zero_opening_and_unregistered_legacy_opening_are_distinguished(): void
    {
        $issuer = $this->actor();
        $collector = $this->actor();
        $writer = app(GatedTreasuryOpeningFundingWriter::class);
        $legacy = app(TreasuryRegisterService::class)->open($issuer, $this->data('QA-LEGACY'));
        $this->rejects('gated_opening_funding_unregistered_or_conflicting_source',
            fn () => $writer->open($issuer, $this->data('QA-LEGACY'), self::UUID, $this->nowAt()));
        $this->rejects('gated_funding_opening_unregistered',
            fn () => $writer->fund($collector, $issuer, $legacy['id'], '10.25', 'QA-LEGACY-FUND',
                'Independent physical funding receipt', self::OTHER, $this->nowAt()));
        $data = array_replace($this->data(), ['opening_amount' => '0.00']);
        $result = $writer->open($issuer, $data, self::UUID, $this->nowAt());
        $this->assertTrue($result['registry_created']);
        $this->assertDatabaseHas('financial_posting_events', ['source_type' => 'treasury_opening', 'amount_minor' => 0]);
        $this->rejects('gated_opening_funding_operation_conflict',
            fn () => $writer->open($issuer, $this->data('QA-CONFLICT'), self::UUID, $this->nowAt()));
        $this->assertDatabaseCount('treasury_accounts', 2);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('cash_sessions', 0);
    }

    public function test_closed_and_frozen_periods_block_both_operations(): void
    {
        $issuer = $this->actor();
        $collector = $this->actor();
        $writer = app(GatedTreasuryOpeningFundingWriter::class);
        $account = $writer->open($issuer, $this->data(), self::UUID, $this->nowAt())['account'];
        $open = fn () => $writer->open($issuer, $this->data('QA-BLOCKED'), self::OTHER, $this->nowAt());
        $fund = fn () => $writer->fund($collector, $issuer, $account['id'], '10.25', 'QA-BLOCKED-FUND',
            'Independent physical funding receipt', self::OTHER, $this->nowAt());
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $open);
        $this->rejects('financial_period_not_open', $fund);
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::OTHER]);
        $this->rejects('financial_scope_not_active', $open);
        $this->rejects('financial_scope_not_active', $fund);
        $this->assertDatabaseCount('treasury_accounts', 1);
        $this->assertDatabaseCount('cash_sessions', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_funding_registry_failure_rolls_back_session_and_audits_then_retries(): void
    {
        $issuer = $this->actor();
        $collector = $this->actor();
        $writer = app(GatedTreasuryOpeningFundingWriter::class);
        $account = $writer->open($issuer, $this->data(), self::UUID, $this->nowAt())['account'];
        $auditCount = AuditEvent::query()->count();
        $fund = fn () => $writer->fund($collector, $issuer, $account['id'], '10.25',
            'QA-ROLLBACK-FUND', 'Independent physical funding receipt', self::OTHER, $this->nowAt());
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'";
        DB::unprepared($trigger);
        try {
            $fund();
            $this->fail('Registry insert should fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_registry_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_registry_failure');
        }
        $this->assertDatabaseCount('cash_sessions', 0);
        $this->assertDatabaseCount('cash_session_fundings', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_operations', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
        $this->assertSame($auditCount, AuditEvent::query()->count());
        $this->assertTrue($fund()['registry_created']);
        $this->assertDatabaseCount('cash_sessions', 1);
        $this->assertDatabaseCount('cash_session_fundings', 1);
        $this->assertDatabaseCount('treasury_postings', 1);
        $this->assertSame(1, AuditEvent::query()->where('event', 'treasury.collector_funded')->count());
    }
}
