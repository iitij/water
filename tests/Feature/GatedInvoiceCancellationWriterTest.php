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
use App\Models\Reading;
use App\Models\Tariff;
use App\Models\Transaction;
use App\Services\InvoiceService;
use App\Services\FinancialLedgerEvidence;
use App\Services\GatedInvoiceCancellationWriter;
use App\Services\AllocationSnapshotIntegrity;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedInvoiceCancellationWriterTest extends TestCase
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

    private const CANCEL = '33333333-3333-4333-8333-333333333333';
    private const REBILL = '44444444-4444-4444-8444-444444444444';
    private const REASON = 'Synthetic documented invoice cancellation';

    private function issue(Subscriber $subscriber, Meter $meter, string $uuid): Invoice
    {
        // Actual isolated invoice source; independent of unaccepted round 70 adapter.
        return $this->gate(function (array $context) use ($subscriber, $meter, $uuid): Invoice {
            $invoice = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
            $entry = Transaction::query()->where('reference_type', Invoice::class)
                ->where('reference_id', $invoice->id)->where('type', 'invoice')->sole();
            $leg = app(FinancialLedgerEvidence::class)->leg((int) $entry->id);
            $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, $uuid, [$leg], $this->nowAt()));
            return $invoice;
        });
    }

    private function fixture(bool $registered = true, bool $zero = false): array
    {
        $actor = $this->actor();
        $collector = $this->actor();
        $subscriber = Square::create(['name' => 'Synthetic cancellation square'])->subscribers()
            ->create(['name' => 'Synthetic cancellation account', 'status' => 'active']);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-GATED-CANCEL']);
        Tariff::create(['name' => 'January', 'unit_price' => '0.50', 'start_date' => '2026-01-01',
            'end_date' => '2026-01-31', 'is_active' => true]);
        Tariff::create(['name' => 'February', 'unit_price' => '1.50', 'start_date' => '2026-02-01', 'is_active' => true]);
        $first = $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01',
            'previous_reading' => '0.00', 'current_reading' => $zero ? '0.00' : '0.01']);
        $second = $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-03-01',
            'period_start' => '2026-02-01', 'period_end' => '2026-03-01',
            'previous_reading' => $zero ? '0.00' : '0.01', 'current_reading' => $zero ? '0.00' : '0.02']);
        $invoice = $registered ? $this->issue($subscriber, $meter, self::UUID)
            : app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        $payment = $zero ? null : app(GatedCashReceiptWriter::class)->collect($collector, (int) $subscriber->id,
            '0.02', self::OTHER, $this->nowAt(), invoiceId: (int) $invoice->id)['payment'];
        return [$actor, $subscriber, $meter->fresh(), $invoice->fresh(), $payment?->fresh(),
            [(int) $first->id, (int) $second->id]];
    }

    private function post(User $actor, Invoice $invoice, string $uuid = self::CANCEL, string $reason = self::REASON): array
    {
        return app(GatedInvoiceCancellationWriter::class)->cancel($actor, (int) $invoice->id, $reason, $uuid, $this->nowAt());
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
        return ['revision' => (array) $revision, 'rows' => DB::table('payment_allocations')
            ->where('revision_id', $revision->id)->orderBy('position')->get()->map(fn ($r) => (array) $r)->all()];
    }

    private function rawReadings(array $ids): array
    {
        return Reading::query()->whereIn('id', $ids)->orderBy('id')->get()
            ->map(fn ($r) => $r->getRawOriginal())->all();
    }

    public function test_actual_cancellation_releases_credit_and_readings_then_rebill_and_exact_replay(): void
    {
        [$actor, $subscriber, $meter, $invoice, $payment, $ids] = $this->fixture();
        $this->assertSame('0.03', $invoice->total_amount);
        $this->assertSame(-1, $subscriber->fresh()->getAccountBalanceMinor());
        $original = (array) DB::table('financial_posting_events')->where('operation_uuid', self::UUID)->sole();
        $paymentBytes = $payment->getRawOriginal();
        $pricing = DB::table('invoice_tariff_lines')->where('invoice_id', $invoice->id)->orderBy('id')->get()->all();
        $result = $this->post($actor, $invoice);
        $this->assertTrue($result['registry_created']);
        $this->assertSame('cancelled', $result['invoice']->status);
        $this->assertSame('0.00', $result['invoice']->amount_paid);
        $this->assertSame(2, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame($paymentBytes, $payment->fresh()->getRawOriginal());
        $this->assertEquals($pricing, DB::table('invoice_tariff_lines')->where('invoice_id', $invoice->id)->orderBy('id')->get()->all());
        $this->assertSame(2, Reading::query()->whereIn('id', $ids)->whereNull('invoice_id')->where('is_invoiced', false)->count());
        $this->assertSame(2, (int) $this->allocation($subscriber)['revision']['unallocated_minor']);
        $this->assertSame($original, (array) DB::table('financial_posting_events')->where('operation_uuid', self::UUID)->sole());
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::CANCEL,
            'source_id' => (string) $result['reversal_id'], 'amount_minor' => 3]);
        $again = $this->post($actor, $invoice);
        $this->assertFalse($again['registry_created']);
        $this->assertSame($result['reversal_id'], $again['reversal_id']);
        $replacement = $this->issue($subscriber, $meter, self::REBILL);
        $this->assertSame('0.03', $replacement->total_amount);
        $this->assertSame('0.02', $replacement->fresh()->amount_paid);
        $this->assertSame(-1, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertFalse($this->post($actor, $invoice)['registry_created']);
        $this->assertSame(2, Reading::query()->whereIn('id', $ids)->where('invoice_id', $replacement->id)->count());
        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 4);
        $this->assertDatabaseCount('financial_posting_events', 4);
        $this->assertDatabaseCount('treasury_postings', 0);
        $this->assertSame(1, AuditEvent::query()->where('event', 'invoice.cancelled')->count());
        $this->gate(function (array $context): void {
            $verified = app(FinancialLedgerRegistryVerifier::class)->verifyPeriod('water', $context['period_id']);
            $this->assertSame(4, $verified['registered_ledger_legs_verified']);
            $this->assertFalse($verified['source_coverage_verified']);
        });
    }

    public function test_zero_consumption_cancellation_retains_both_zero_effect_identities(): void
    {
        [$actor, $subscriber, , $invoice, , $ids] = $this->fixture(zero: true);
        $result = $this->post($actor, $invoice);
        $this->assertTrue($result['registry_created']);
        $this->assertSame(0, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::CANCEL,
            'source_id' => (string) $result['reversal_id'], 'amount_minor' => 0]);
        $this->assertSame(2, Reading::query()->whereIn('id', $ids)->whereNull('invoice_id')->where('is_invoiced', false)->count());
        $this->assertFalse($this->post($actor, $invoice)['registry_created']);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('financial_posting_events', 2);
    }

    public function test_older_invoice_cancellation_cannot_break_observation_chain(): void
    {
        [$actor, $subscriber, $meter, $invoice, , $ids] = $this->fixture();
        $third = $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-04-01',
            'period_start' => '2026-03-01', 'period_end' => '2026-04-01',
            'previous_reading' => '0.02', 'current_reading' => '0.03']);
        $later = $this->issue($subscriber, $meter, self::REBILL);
        $before = $subscriber->fresh()->getAccountBalanceMinor();
        $allocation = $this->allocation($subscriber);
        $readings = $this->rawReadings([...$ids, (int) $third->id]);
        $auditCount = AuditEvent::query()->count();
        $this->rejects('gated_invoice_cancel_chain_gap', fn () => $this->post($actor, $invoice));
        $this->assertNotSame('cancelled', $invoice->fresh()->status);
        $this->assertSame($later->id, $third->fresh()->invoice_id);
        $this->assertSame($before, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame($allocation, $this->allocation($subscriber));
        $this->assertSame($readings, $this->rawReadings([...$ids, (int) $third->id]));
        $this->assertSame($auditCount, AuditEvent::query()->count());
        $this->assertDatabaseCount('transactions', 3);
        $this->assertDatabaseCount('financial_posting_events', 3);
    }

    public function test_legacy_source_and_closed_frozen_periods_are_rejected(): void
    {
        [$actor, $subscriber, , $invoice] = $this->fixture(false);
        $this->rejects('gated_invoice_cancel_prerequisite_unregistered', fn () => $this->post($actor, $invoice));
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', fn () => $this->post($actor, $invoice));
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::CANCEL]);
        $this->rejects('financial_scope_not_active', fn () => $this->post($actor, $invoice));
        $this->assertNotSame('cancelled', $invoice->fresh()->status);
        $this->assertSame(-1, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_replay_conflicts_and_original_pricing_tamper_rejected(): void
    {
        [$actor, , , $invoice, , $ids] = $this->fixture();
        $this->post($actor, $invoice);
        $this->rejects('gated_invoice_cancel_operation_conflict',
            fn () => $this->post($actor, $invoice, reason: 'Changed cancellation reason'));
        $this->rejects('gated_invoice_cancel_operation_conflict',
            fn () => $this->post($actor, $invoice, self::REBILL));
        DB::table('invoice_tariff_lines')->where('invoice_id', $invoice->id)
            ->where('reading_id', $ids[0])->update(['tariff_name' => 'Tampered historical name']);
        $this->rejects('gated_invoice_cancel_audit_mismatch', fn () => $this->post($actor, $invoice));
        $this->assertDatabaseCount('transactions', 3);
        $this->assertDatabaseCount('financial_posting_events', 3);
    }

    public function test_registry_failure_restores_invoice_readings_credit_and_audit(): void
    {
        [$actor, $subscriber, , $invoice, $payment, $ids] = $this->fixture();
        $before = $this->allocation($subscriber);
        $invoiceBytes = $invoice->getRawOriginal();
        $paymentBytes = $payment->getRawOriginal();
        $readings = $this->rawReadings($ids);
        $auditCount = AuditEvent::query()->count();
        $revisions = DB::table('payment_allocation_revisions')->count();
        $rows = DB::table('payment_allocations')->count();
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'";
        DB::unprepared($trigger);
        try {
            $this->post($actor, $invoice);
            $this->fail('Registry insert should fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('synthetic_registry_failure', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS qa_registry_failure');
        }
        $this->assertSame(-1, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame($before, $this->allocation($subscriber));
        $this->assertSame($invoiceBytes, $invoice->fresh()->getRawOriginal());
        $this->assertSame($paymentBytes, $payment->fresh()->getRawOriginal());
        $this->assertSame($readings, $this->rawReadings($ids));
        $this->assertSame($auditCount, AuditEvent::query()->count());
        $this->assertDatabaseCount('payment_allocation_revisions', $revisions);
        $this->assertDatabaseCount('payment_allocations', $rows);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('financial_posting_operations', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertTrue($this->post($actor, $invoice)['registry_created']);
        $this->assertSame(2, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('financial_posting_events', 3);
    }
}
