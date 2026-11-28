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
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedCashReceiptWriterTest extends TestCase
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
        app(\App\Services\LedgerProtection::class)->install();
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

    private function subscriber(): Subscriber
    {
        return Subscriber::create(['name' => 'Synthetic receipt account',
            'square_id' => Square::create(['name' => 'Synthetic receipt square'])->id, 'status' => 'active']);
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

    public function test_subscriber_financial_writers_reject_missing_live_source_protection(): void
    {
        $actor = $this->actor();
        $subscriber = $this->subscriber();
        $writer = app(GatedCashReceiptWriter::class);
        $documents = app(\App\Services\GatedInvoiceTransferWriter::class);
        $post = fn () => $writer->collect($actor, $subscriber->id, '10.25', self::UUID, $this->nowAt());
        $tables = ['payments', 'invoices', 'transactions', 'payment_allocations',
            'payment_allocation_revisions', 'financial_posting_operations', 'financial_posting_events', 'audit_events'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toArray(), $tables);
        foreach ([
            ['ledger_reject_update', \App\Services\LedgerProtection::class],
            ['ledger_reject_delete', \App\Services\LedgerProtection::class],
            ['fin_ledger_source_insert', \App\Services\LedgerSourceInsertProtection::class],
            ['fin_registry_source_identity_insert', \App\Services\RegistrySourceIdentityProtection::class],
            ['reg_invoice_projection_update', \App\Services\InvoiceProjectionProtection::class],
        ] as [$trigger, $guard]) {
            $before = $snapshot();
            DB::unprepared('DROP TRIGGER '.$trigger);
            try {
                foreach ([
                    $post,
                    fn () => $documents->issue($actor, $subscriber->id, 999999, self::OTHER, $this->nowAt()),
                    fn () => $documents->cancel($actor, 999999, 'Synthetic cancellation', self::OTHER, $this->nowAt()),
                    fn () => $documents->transfer($actor, 999999, $subscriber->id, 'Synthetic transfer', 'Synthetic independent proof', self::OTHER, $this->nowAt()),
                ] as $action) {
                    $this->rejects('subscriber_financial_write_protection_missing', $action);
                    $this->assertEquals($before, $snapshot());
                }
            } finally {
                app($guard)->install();
            }
        }
        $this->assertTrue($post()['registry_created']);
        $this->assertFalse($post()['registry_created']);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
    }

    public function test_release_cash_service_fence_blocks_bypass_and_preserves_exact_replay(): void
    {
        $actor = $this->actor();
        $subscriber = $this->subscriber();
        $service = app(PaymentService::class);
        $writer = app(GatedCashReceiptWriter::class);
        $direct = fn (?GatedCashReceiptWriter $candidate = null) => $service->collectPayment(
            $subscriber, '10.25', userId: $actor->id, paymentDate: '2026-11-25',
            syncUuid: self::UUID, gatedWriter: $candidate);
        $post = fn () => $writer->collect($actor, $subscriber->id, '10.25', self::UUID, $this->nowAt());

        config(['financial_candidate.cash_receipts' => true]);
        $this->rejects('gated_cash_writer_required', $direct);
        $this->rejects('gated_cash_writer_required', fn () => DB::transaction(fn () => $direct($writer)));
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $post);
        foreach (['payments', 'transactions', 'financial_posting_operations', 'financial_posting_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, $subscriber->fresh()->getAccountBalanceMinor());
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        $result = $post();
        $this->assertTrue($result['registry_created']);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertFalse($writer->allowsMutation((int) $subscriber->id, self::UUID));

        config(['financial_candidate.cash_receipts' => false]);
        $this->rejects('gated_cash_writer_required', $direct);
        $this->rejects('gated_cash_writer_required', fn () => DB::transaction(fn () => $direct($writer)));
        $again = $post();
        $this->assertFalse($again['registry_created']);
        $this->assertSame($result['payment']->id, $again['payment']->id);
        foreach (['payments', 'transactions', 'financial_posting_operations', 'financial_posting_events'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('treasury_postings', 0);
        // Default-off legacy compatibility for a distinct unregistered operation.
        $legacy = $service->collectPayment($subscriber, '1.00', userId: $actor->id,
            paymentDate: '2026-11-25', syncUuid: self::OTHER);
        $this->assertNotSame($result['payment']->id, $legacy->id);
        $this->assertSame(1125, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('financial_posting_operations', 1);
    }

    public function test_direct_cash_receipt_and_exact_replay_register_only_subscriber_effect(): void
    {
        $actor = $this->actor();
        $subscriber = $this->subscriber();
        $writer = app(GatedCashReceiptWriter::class);
        $post = fn () => $writer->collect($actor, $subscriber->id, '10.25', self::UUID, $this->nowAt());
        $result = $post();
        $this->assertTrue($result['registry_created']);
        $this->assertSame('10.25', $result['payment']->amount);
        $this->assertNull($result['payment']->cash_session_id);
        $again = $post();
        $this->assertFalse($again['registry_created']);
        $this->assertSame($result['payment']->id, $again['payment']->id);
        $this->assertSame($result['transaction_id'], $again['transaction_id']);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        foreach (['payments', 'transactions', 'financial_posting_operations', 'financial_posting_events'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->gate(function (array $context): void {
            $verified = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(1, $verified['registered_ledger_legs_verified']);
            $this->assertFalse($verified['source_coverage_verified']);
        });
        try {
            $writer->collect($actor, $subscriber->id, '10.26', self::UUID, $this->nowAt());
            $this->fail('Changed amount accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
    }

    public function test_explicit_till_identity_and_rollback_when_till_omitted(): void
    {
        $actor = $this->actor();
        $subscriber = $this->subscriber();
        $session = app(CashSessionService::class)->open($actor);
        $writer = app(GatedCashReceiptWriter::class);
        $this->rejects('gated_cash_destination_mismatch',
            fn () => $writer->collect($actor, $subscriber->id, '10.25', self::UUID, $this->nowAt()));
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('financial_posting_events', 0);
        $result = $writer->collect($actor, $subscriber->id, '10.25', self::UUID, $this->nowAt(), (int) $session->id);
        $this->assertTrue($result['registry_created']);
        $this->assertSame((int) $session->id, (int) $result['payment']->cash_session_id);
        $this->assertSame(1025, app(CashSessionService::class)->expectedAmountMinor($session->fresh()));
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('treasury_postings', 0);
    }

    public function test_legacy_receipt_not_adopted_and_closed_frozen_periods_block_new_receipt(): void
    {
        $actor = $this->actor();
        $subscriber = $this->subscriber();
        $writer = app(GatedCashReceiptWriter::class);
        app(PaymentService::class)->collectPayment($subscriber, '10.25', userId: $actor->id,
            paymentDate: '2026-11-25', syncUuid: self::UUID);
        $this->rejects('gated_cash_unregistered_or_conflicting_source',
            fn () => $writer->collect($actor, $subscriber->id, '10.25', self::UUID, $this->nowAt()));
        $post = fn () => $writer->collect($actor, $subscriber->id, '10.25', self::OTHER, $this->nowAt());
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $post);
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::OTHER]);
        $this->rejects('financial_scope_not_active', $post);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_events', 0);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
    }

    public function test_registry_insert_failure_rolls_back_receipt_and_ledger_then_exact_retry(): void
    {
        $actor = $this->actor();
        $subscriber = $this->subscriber();
        $writer = app(GatedCashReceiptWriter::class);
        $post = fn () => $writer->collect($actor, $subscriber->id, '10.25', self::UUID, $this->nowAt());
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'";
        DB::unprepared($trigger);
        try {
            $post();
            $this->fail('Registry insert should fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_registry_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_registry_failure');
        }
        foreach (['payments', 'transactions', 'financial_posting_operations', 'financial_posting_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertTrue($post()['registry_created']);
        $this->assertSame(1025, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }
}
