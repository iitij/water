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
use App\Models\Transaction;
use App\Services\InvoiceService;
use App\Services\SingleMeterService;
use App\Services\FinancialLedgerEvidence;
use App\Services\GatedHistoricalInvoiceCorrectionWriter;
use App\Services\AllocationSnapshotIntegrity;
use App\Services\CashSessionService;
use App\Services\FinancialLedgerRegistryVerifier;
use DomainException;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatedHistoricalInvoiceCorrectionWriterTest extends TestCase
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
    private const REASON = 'Synthetic historical measured consumption correction';
    private const EVIDENCE = 'Independent original observation evidence';

    private function fixture(bool $registered = true): array
    {
        $actor = $this->actor();
        $collector = $this->actor();
        $subscriber = Square::create(['name' => 'Synthetic correction square'])->subscribers()
            ->create(['name' => 'Synthetic correction account', 'status' => 'active']);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-GATED-CORRECTION']);
        Tariff::create(['name' => 'January', 'unit_price' => '0.50', 'start_date' => '2026-01-01',
            'end_date' => '2026-01-31', 'is_active' => true]);
        Tariff::create(['name' => 'February', 'unit_price' => '1.50', 'start_date' => '2026-02-01', 'is_active' => true]);
        $first = $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01',
            'previous_reading' => '0.00', 'current_reading' => '0.01']);
        $second = $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-03-01',
            'period_start' => '2026-02-01', 'period_end' => '2026-03-01',
            'previous_reading' => '0.01', 'current_reading' => '0.02']);
        // Actual invoice service + explicit synthetic registered prerequisite.
        // Do not depend on the unaccepted round 70 writer or adopt legacy data.
        $create = function () use ($subscriber, $meter): Invoice {
            return app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        };
        $invoice = $registered ? $this->gate(function (array $context) use ($create): Invoice {
            $invoice = $create();
            $entry = Transaction::query()->where('reference_type', Invoice::class)->where('reference_id', $invoice->id)
                ->where('type', 'invoice')->sole();
            $leg = app(FinancialLedgerEvidence::class)->leg((int) $entry->id);
            $this->assertTrue(app(FinancialPostingRegistry::class)->record($context, self::UUID, [$leg], $this->nowAt()));
            return $invoice;
        }) : $create();
        $payment = app(GatedCashReceiptWriter::class)->collect($collector, (int) $subscriber->id,
            '0.02', self::OTHER, $this->nowAt(), invoiceId: (int) $invoice->id)['payment'];
        app(SingleMeterService::class)->replace($meter, ['unique_code' => 'QA-GATED-CORRECTION-NEW'],
            '2026-03-02', 'Synthetic replacement after original observations');
        $payload = [['reading_id' => (int) $first->id, 'corrected_units' => '0.03'],
            ['reading_id' => (int) $second->id, 'corrected_units' => '0.03']];
        return [$actor, $subscriber, $invoice->fresh(), $payment->fresh(), $payload];
    }

    private function post(User $actor, Invoice $invoice, array $payload, string $uuid = self::CORRECTION,
        string $reason = self::REASON): array
    {
        return app(GatedHistoricalInvoiceCorrectionWriter::class)->correct($actor, (int) $invoice->id,
            $payload, $reason, self::EVIDENCE, $uuid, $this->nowAt());
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

    public function test_two_historical_tariffs_reversal_replacement_and_exact_replay(): void
    {
        [$actor, $subscriber, $invoice, $payment, $payload] = $this->fixture();
        $this->assertSame(-1, $subscriber->fresh()->getAccountBalanceMinor());
        $original = (array) DB::table('financial_posting_events')->where('operation_uuid', self::UUID)->sole();
        $readings = $invoice->readings()->orderBy('id')->get()->map(fn ($r) => $r->getRawOriginal())->all();
        $paymentBytes = $payment->getRawOriginal();
        $result = $this->post($actor, $invoice, $payload);
        $this->assertTrue($result['registry_created']);
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('0.07', $result['invoice']->total_amount);
        $this->assertSame('0.02', $result['invoice']->fresh()->amount_paid);
        $this->assertSame(-5, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame($readings, $invoice->readings()->orderBy('id')->get()->map(fn ($r) => $r->getRawOriginal())->all());
        $this->assertSame($paymentBytes, $payment->fresh()->getRawOriginal());
        $this->assertSame($original, (array) DB::table('financial_posting_events')->where('operation_uuid', self::UUID)->sole());
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::CORRECTION,
            'source_id' => (string) $result['reversal_id'], 'amount_minor' => 3]);
        $this->assertDatabaseHas('financial_posting_events', ['operation_uuid' => self::CORRECTION,
            'source_id' => (string) $result['posting_id'], 'amount_minor' => -7]);
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $result['invoice']->id,
            'reading_id' => $payload[0]['reading_id'], 'amount' => '0.02']);
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $result['invoice']->id,
            'reading_id' => $payload[1]['reading_id'], 'amount' => '0.05']);
        $again = $this->post($actor, $invoice, array_reverse($payload));
        $this->assertFalse($again['registry_created']);
        $this->assertSame($result['invoice']->id, $again['invoice']->id);
        $this->assertDatabaseCount('historical_invoice_corrections', 1);
        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 4);
        $this->assertDatabaseCount('financial_posting_events', 4);
        $this->assertSame(1, AuditEvent::query()->where('event', 'invoice.historical_corrected')->count());
    }

    public function test_unregistered_original_is_not_adopted(): void
    {
        [$actor, $subscriber, $invoice, , $payload] = $this->fixture(false);
        $this->rejects('gated_correction_prerequisite_unregistered', fn () => $this->post($actor, $invoice, $payload));
        $this->assertSame(-1, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('historical_invoice_corrections', 0);
        $this->assertDatabaseCount('financial_posting_events', 1);
    }

    public function test_reading_identity_conflicts_and_closed_or_frozen_period(): void
    {
        [$actor, $subscriber, $invoice, , $payload] = $this->fixture();
        $post = fn () => $this->post($actor, $invoice, $payload);
        $this->rejects('gated_correction_readings', fn () => $this->post($actor, $invoice, [$payload[0], $payload[0]]));
        $this->rejects('gated_correction_reading_identity', fn () => $this->post($actor, $invoice, [$payload[0]]));
        DB::table('financial_periods')->update(['state' => 'CLOSED']);
        $this->rejects('financial_period_not_open', $post);
        DB::table('financial_periods')->update(['state' => 'OPEN']);
        DB::table('financial_scopes')->update(['active_freeze_uuid' => self::CORRECTION]);
        $this->rejects('financial_scope_not_active', $post);
        $this->assertSame(-1, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('historical_invoice_corrections', 0);
        $this->assertDatabaseCount('financial_posting_events', 2);
    }

    public function test_replay_rejects_changed_decision_and_tampered_replacement_pricing(): void
    {
        [$actor, , $invoice, , $payload] = $this->fixture();
        $result = $this->post($actor, $invoice, $payload);
        $this->rejects('gated_correction_operation_conflict',
            fn () => $this->post($actor, $invoice, $payload, reason: 'Changed correction reason'));
        $this->rejects('gated_correction_operation_conflict',
            fn () => $this->post($actor, $invoice, $payload, '44444444-4444-4444-8444-444444444444'));
        DB::table('invoice_tariff_lines')->where('invoice_id', $result['invoice']->id)
            ->where('reading_id', $payload[0]['reading_id'])->update(['amount' => '0.03']);
        $this->rejects('gated_correction_pricing_mismatch', fn () => $this->post($actor, $invoice, $payload));
        $this->assertDatabaseCount('historical_invoice_corrections', 1);
        $this->assertDatabaseCount('financial_posting_events', 4);
    }

    public function test_failed_registry_insert_restores_invoice_readings_and_allocation(): void
    {
        [$actor, $subscriber, $invoice, $payment, $payload] = $this->fixture();
        $before = $this->allocation($subscriber);
        $invoiceBytes = $invoice->getRawOriginal();
        $paymentBytes = $payment->getRawOriginal();
        $readingBytes = $invoice->readings()->orderBy('id')->get()->map(fn ($r) => $r->getRawOriginal())->all();
        $auditCount = AuditEvent::query()->count();
        $revisions = DB::table('payment_allocation_revisions')->count();
        $rows = DB::table('payment_allocations')->count();
        $trigger = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events BEGIN SELECT RAISE(ABORT, 'synthetic_registry_failure'); END"
            : "CREATE TRIGGER qa_registry_failure BEFORE INSERT ON financial_posting_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_registry_failure'";
        DB::unprepared($trigger);
        try {
            $this->post($actor, $invoice, $payload);
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
        $this->assertSame($readingBytes, $invoice->readings()->orderBy('id')->get()->map(fn ($r) => $r->getRawOriginal())->all());
        $this->assertSame($auditCount, AuditEvent::query()->count());
        $this->assertDatabaseCount('payment_allocation_revisions', $revisions);
        $this->assertDatabaseCount('payment_allocations', $rows);
        $this->assertDatabaseCount('historical_invoice_corrections', 0);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_tariff_lines', 2);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);
        $this->assertTrue($this->post($actor, $invoice, $payload)['registry_created']);
        $this->assertSame(-5, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertDatabaseCount('financial_posting_events', 4);
    }
}
