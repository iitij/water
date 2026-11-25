<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Square;
use App\Models\Transaction;
use App\Services\FinancialLedgerEvidence;
use App\Services\FinancialPostingRegistry;
use App\Services\FinancialWriteGate;
use App\Services\PaymentService;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Tariff;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\HistoricalInvoiceCorrectionService;
use App\Services\InvoiceService;
use App\Services\SingleMeterService;
use Spatie\Permission\Models\Role;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FinancialHistoricalLedgerEvidenceTest extends TestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';

    private const CORRECTION = '33333333-3333-4333-8333-333333333333';

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

    private function account(): Subscriber
    {
        return Subscriber::create(['name' => 'Synthetic correction account',
            'square_id' => Square::create(['name' => 'Synthetic evidence'])->id, 'status' => 'active']);
    }

    private function actor(): User
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $actor;
    }

    private function leg(string $type, string $class, int $id): array
    {
        $entry = Transaction::where('type', $type)->where('reference_type', $class)
            ->where('reference_id', $id)->sole();
        return app(FinancialLedgerEvidence::class)->leg((int) $entry->id);
    }

    public function test_actual_retirement_and_historical_correction_preserve_source_identity(): void
    {
        $subscriber = $this->account();
        $actor = $this->actor();
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'QA-LEDGER-HISTORY']);
        Tariff::create(['name' => 'QA January', 'unit_price' => '0.50', 'start_date' => '2026-01-01',
            'end_date' => '2026-01-31', 'is_active' => true]);
        Tariff::create(['name' => 'QA February', 'unit_price' => '1.50', 'start_date' => '2026-02-01', 'is_active' => true]);
        $first = $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'previous_reading' => '0.00', 'current_reading' => '0.01']);
        $second = $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-03-01',
            'period_start' => '2026-02-01', 'period_end' => '2026-03-01', 'previous_reading' => '0.01', 'current_reading' => '0.02']);
        $registry = app(FinancialPostingRegistry::class);

        // Explicit synthetic open-period context; not an inference of effective dates
        // from reading dates or a production writer activation.
        [$invoice, $original] = $this->gate(function (array $context) use ($subscriber, $meter, $registry): array {
            $invoice = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
            $leg = $this->leg('invoice', Invoice::class, $invoice->id);
            $this->assertSame(-3, $leg['amount_minor']);
            $this->assertTrue($registry->record($context, self::UUID, [$leg], $this->nowAt()));
            return [$invoice, $leg];
        });
        [$payment, $receipt] = $this->gate(function (array $context) use ($subscriber, $invoice, $registry): array {
            $payment = app(PaymentService::class)->collectPayment($subscriber, '0.02', $invoice->id);
            $leg = $this->leg('payment', Payment::class, $payment->id);
            $this->assertSame(2, $leg['amount_minor']);
            $this->assertTrue($registry->record($context, self::OTHER, [$leg], $this->nowAt()));
            return [$payment, $leg];
        });
        $this->gate(function () use ($meter, $invoice, $payment, $original, $receipt): void {
            app(SingleMeterService::class)->replace($meter, ['unique_code' => 'QA-LEDGER-HISTORY-NEW'],
                '2026-03-02', 'Synthetic replacement after billing');
            $this->assertNull($invoice->fresh()->meter_id);
            $this->assertSame((int) $meter->id, (int) $invoice->fresh()->retired_meter_id);
            $this->assertSame($original, $this->leg('invoice', Invoice::class, $invoice->id));
            $this->assertSame($receipt, $this->leg('payment', Payment::class, $payment->id));
        });
        $this->assertSame(-1, $subscriber->fresh()->getAccountBalanceMinor());
        $payload = [['reading_id' => $first->id, 'corrected_units' => '0.03'],
            ['reading_id' => $second->id, 'corrected_units' => '0.03']];
        $post = function (array $context) use ($invoice, $actor, $payload, $payment, $original, $receipt, $registry): void {
            $correction = app(HistoricalInvoiceCorrectionService::class)->correctReadings($invoice->fresh(), $actor,
                $payload, 'Synthetic evidenced historical correction', 'Synthetic measured historical source evidence');
            $replacement = Invoice::findOrFail($correction->replacement_invoice_id);
            $this->assertSame('0.07', $replacement->total_amount);
            $this->assertSame('cancelled', $invoice->fresh()->status);
            $out = $this->leg('invoice_reversal', Invoice::class, $invoice->id);
            $in = $this->leg('invoice', Invoice::class, $replacement->id);
            $this->assertSame(3, $out['amount_minor']);
            $this->assertSame(-7, $in['amount_minor']);
            $this->assertSame($original, $this->leg('invoice', Invoice::class, $invoice->id));
            $this->assertSame($receipt, $this->leg('payment', Payment::class, $payment->id));
            $this->assertTrue($registry->record($context, self::CORRECTION, [$out, $in], $this->nowAt()));
        };
        try {
            $this->gate(function (array $context) use ($post): void {
                $post($context);
                throw new \RuntimeException('synthetic historical correction rollback');
            });
            $this->fail('Expected outer rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic historical correction rollback', $e->getMessage());
        }
        $this->assertSame(-1, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertNotSame('cancelled', $invoice->fresh()->status);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('historical_invoice_corrections', 0);
        $this->assertDatabaseCount('financial_posting_operations', 2);
        $this->assertDatabaseCount('financial_posting_events', 2);

        $this->gate($post);
        $this->assertSame(-5, $subscriber->fresh()->getAccountBalanceMinor());
        $this->assertSame($invoice->id, $first->fresh()->invoice_id);
        $this->assertSame($invoice->id, $second->fresh()->invoice_id);
        $this->assertSame($invoice->id, $payment->fresh()->invoice_id);
        $this->gate(function (array $context) use ($registry): void {
            $result = $registry->verifyPeriod('water', $context['period_id']);
            $this->assertSame(3, $result['operations']);
            $this->assertSame(4, $result['events']);
            $this->assertFalse($result['source_coverage_verified']);
        });
    }
}
