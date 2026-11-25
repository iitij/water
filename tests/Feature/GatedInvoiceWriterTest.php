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
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Tariff;
use App\Services\GatedInvoiceWriter;
use App\Services\InvoiceService;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedInvoiceWriterTest extends TestCase
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

    private function fixture(bool $zero = false): array
    {
        $subscriber = Square::create(['name' => 'Synthetic invoice square'])->subscribers()
            ->create(['name' => 'Synthetic invoice account', 'status' => 'active']);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-GATED-INVOICE']);
        Tariff::create(['name' => 'QA January', 'unit_price' => '0.50', 'start_date' => '2026-01-01',
            'end_date' => '2026-01-31', 'is_active' => true]);
        Tariff::create(['name' => 'QA February', 'unit_price' => '1.50', 'start_date' => '2026-02-01', 'is_active' => true]);
        $first = $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'previous_reading' => '0.00',
            'current_reading' => $zero ? '0.00' : '0.01']);
        $second = $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-03-01',
            'period_start' => '2026-02-01', 'period_end' => '2026-03-01', 'previous_reading' => $zero ? '0.00' : '0.01',
            'current_reading' => $zero ? '0.00' : '0.02']);
        return [$this->actor(), $subscriber, $meter, [(int) $first->id, (int) $second->id]];
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

    public function test_actual_invoice_and_targeted_cash_receipt_with_exact_replay(): void
    {
        [$actor, $subscriber, $meter, $ids] = $this->fixture();
        $writer = app(GatedInvoiceWriter::class);
        $result = $writer->issue($actor, $subscriber->id, $meter->id, $ids, self::UUID, $this->nowAt());
        $invoice = $result['invoice'];
        $this->assertTrue($result['registry_created']);
        $this->assertSame('0.03', $invoice->total_amount);
        $this->assertSame('2026-11-25', $invoice->bill_date->toDateString());
        $this->assertSame('2026-01-01', $invoice->billing_period_start->toDateString());
        $this->assertSame('2026-03-01', $invoice->billing_period_end->toDateString());
        $this->assertSame(-3, $subscriber->fresh()->getAccountBalanceMinor());
        $payment = app(GatedCashReceiptWriter::class)->collect($actor, $subscriber->id, '0.02', self::OTHER,
            $this->nowAt(), invoiceId: (int) $invoice->id);
        $this->assertTrue($payment['registry_created']);
        $this->assertSame('0.02', $invoice->fresh()->amount_paid);
        $this->assertSame(1, $invoice->fresh()->getRemainingMinor());
        $this->assertSame(-1, $subscriber->fresh()->getAccountBalanceMinor());
        $again = $writer->issue($actor, $subscriber->id, $meter->id, array_reverse($ids), self::UUID, $this->nowAt());
        $this->assertFalse($again['registry_created']);
        $this->assertSame($invoice->id, $again['invoice']->id);
        $this->assertSame($result['transaction_id'], $again['transaction_id']);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('financial_posting_operations', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertSame(1, AuditEvent::query()->where('event', 'financial.gated_invoice_issued')->count());
        $this->gate(function (array $context): void {
            $result = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(2, $result['registered_ledger_legs_verified']);
            $this->assertFalse($result['source_coverage_verified']);
        });
    }

    public function test_complete_reading_set_conflicts_and_closed_frozen_period_rejection(): void
    {
        [$actor, $subscriber, $meter, $ids] = $this->fixture();
        $writer = app(GatedInvoiceWriter::class);
        $post = fn () => $writer->issue($actor, $subscriber->id, $meter->id, $ids, self::UUID, $this->nowAt());
        $this->rejects('gated_invoice_readings_invalid',
            fn () => $writer->issue($actor, $subscriber->id, $meter->id, [$ids[0], $ids[0]], self::UUID, $this->nowAt()));
        $this->rejects('gated_invoice_pending_set_changed',
            fn () => $writer->issue($actor, $subscriber->id, $meter->id, [$ids[0]], self::UUID, $this->nowAt()));
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $post);
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::OTHER]);
        $this->rejects('financial_scope_not_active', $post);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => null]);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertTrue($post()['registry_created']);
        $this->rejects('gated_invoice_pending_set_changed',
            fn () => $writer->issue($actor, $subscriber->id, $meter->id, $ids, self::OTHER, $this->nowAt()));
        $this->rejects('gated_invoice_operation_conflict',
            fn () => $writer->issue($this->actor(), $subscriber->id, $meter->id, $ids, self::UUID, $this->nowAt()));
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_legacy_invoice_is_not_adopted(): void
    {
        [$actor, $subscriber, $meter, $ids] = $this->fixture();
        app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->rejects('gated_invoice_pending_set_changed',
            fn () => app(GatedInvoiceWriter::class)->issue($actor, $subscriber->id, $meter->id, $ids, self::UUID, $this->nowAt()));
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_events', 0);
    }

    public function test_registry_failure_restores_readings_and_credit_allocation_then_retries(): void
    {
        [$actor, $subscriber, $meter, $ids] = $this->fixture();
        $payment = app(GatedCashReceiptWriter::class)->collect($actor, $subscriber->id, '0.02', self::OTHER, $this->nowAt());
        $auditCount = AuditEvent::query()->count();
        $post = fn () => app(GatedInvoiceWriter::class)->issue($actor, $subscriber->id, $meter->id, $ids, self::UUID, $this->nowAt());
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
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_tariff_lines', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('financial_posting_events', 1);
        $this->assertSame(2, DB::table('readings')->whereIn('id', $ids)->where('is_invoiced', false)->whereNull('invoice_id')->count());
        $this->assertSame(2, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame($auditCount, AuditEvent::query()->count());
        $result = $post();
        $this->assertTrue($result['registry_created']);
        $this->assertSame('0.02', $result['invoice']->fresh()->amount_paid);
        $this->assertSame(-1, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertSame(2, DB::table('readings')->whereIn('id', $ids)->where('invoice_id', $result['invoice']->id)->count());
    }
}
