<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Models\User;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryTransferService;
use App\Services\TreasuryTransferEvidence;
use App\Services\GatedTreasuryTransferWriter;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedTreasuryTransferWriterTest extends TestCase
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

    private function fixture(): array
    {
        $sender = $this->actor();
        $receiver = $this->actor();
        $from = $this->account($sender, 'QA-GATE-FROM', '100.00');
        $to = $this->account($sender, 'QA-GATE-TO', '0.00');
        return [$sender, $receiver, [
            'from_account_id' => $from['id'], 'to_account_id' => $to['id'],
            'amount' => '10.25', 'reference' => 'QA-GATE-DISPATCH', 'dispatched_on' => '2026-11-25',
            'evidence' => 'Synthetic independently witnessed dispatch',
        ], [
            'amount' => '10.25', 'reference' => 'QA-GATE-RECEIVE', 'received_on' => '2026-11-25',
            'evidence' => 'Synthetic independently witnessed receipt',
        ]];
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

    public function test_gate_first_dispatch_receipt_and_exact_replay(): void
    {
        [$sender, $receiver, $out, $in] = $this->fixture();
        $writer = app(GatedTreasuryTransferWriter::class);
        $sent = $writer->dispatch($sender, $out, self::UUID, $this->nowAt());
        $this->assertTrue($sent['registry_created']);
        $this->assertSame(1025, $sent['transfer']['in_transit_minor']);
        $again = $writer->dispatch($sender, $out, self::UUID, $this->nowAt());
        $this->assertFalse($again['registry_created']);
        $this->assertSame($sent['posting_id'], $again['posting_id']);
        $received = $writer->receive($receiver, $sent['transfer']['id'], $in, self::OTHER, $this->nowAt());
        $this->assertTrue($received['registry_created']);
        $this->assertSame(0, $received['transfer']['in_transit_minor']);
        $again = $writer->receive($receiver, $sent['transfer']['id'], $in, self::OTHER, $this->nowAt());
        $this->assertFalse($again['registry_created']);
        $this->assertSame($received['posting_id'], $again['posting_id']);
        $this->assertDatabaseCount('treasury_transfers', 1);
        $this->assertDatabaseCount('treasury_transfer_receipts', 1);
        $this->assertDatabaseCount('treasury_postings', 2);
        $this->assertDatabaseCount('financial_posting_operations', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertDatabaseCount('transactions', 0);
        $this->gate(function (array $context): void {
            $result = app(\App\Services\FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(2, $result['registered_treasury_legs_verified']);
            $this->assertFalse($result['source_coverage_verified']);
        });
    }

    public function test_closed_frozen_and_nested_transactions_reject_before_business_writes(): void
    {
        [$sender, , $out] = $this->fixture();
        $writer = app(GatedTreasuryTransferWriter::class);
        $post = fn () => $writer->dispatch($sender, $out, self::UUID, $this->nowAt());
        $this->gate(fn () => $this->rejects('financial_gate_must_be_first', $post));
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $post);
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::OTHER]);
        $this->rejects('financial_scope_not_active', $post);
        $this->assertDatabaseCount('treasury_transfers', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_operations', 0);
    }

    public function test_legacy_sources_and_conflicting_uuid_are_not_adopted(): void
    {
        [$sender, $receiver, $out, $in] = $this->fixture();
        $writer = app(GatedTreasuryTransferWriter::class);
        $legacy = app(TreasuryTransferService::class)->dispatch($sender, $out);
        $this->rejects('gated_transfer_unregistered_or_conflicting_source',
            fn () => $writer->dispatch($sender, $out, self::UUID, $this->nowAt()));
        $this->rejects('gated_transfer_dispatch_unregistered',
            fn () => $writer->receive($receiver, $legacy['id'], $in, self::OTHER, $this->nowAt()));
        $new = array_replace($out, ['reference' => 'QA-GATE-NEW']);
        $writer->dispatch($sender, $new, self::UUID, $this->nowAt());
        $this->rejects('gated_transfer_operation_conflict',
            fn () => $writer->dispatch($sender, array_replace($out, ['reference' => 'QA-GATE-CONFLICT']), self::UUID, $this->nowAt()));
        $this->rejects('gated_transfer_unregistered_or_conflicting_source',
            fn () => $writer->dispatch($sender, $new, self::OTHER, $this->nowAt()));
        $this->assertDatabaseCount('treasury_transfers', 2);
        $this->assertDatabaseCount('treasury_transfer_receipts', 0);
        $this->assertDatabaseCount('treasury_postings', 2);
        $this->assertDatabaseCount('financial_posting_operations', 1);
    }

    public function test_registry_failure_rolls_back_business_source_and_audit(): void
    {
        [$sender, , $out] = $this->fixture();
        $auditCount = DB::table('audit_logs')->count();
        // A database-side insert failure occurs after the actual dispatch.
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'";
        DB::unprepared($trigger);
        try {
            app(GatedTreasuryTransferWriter::class)->dispatch($sender, $out, self::UUID, $this->nowAt());
            $this->fail('Registry insert should fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_registry_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_registry_failure');
        }
        $this->assertDatabaseCount('treasury_transfers', 0);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertDatabaseCount('financial_posting_events', 0);
        $this->assertDatabaseCount('audit_logs', $auditCount);
    }
}
