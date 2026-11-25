<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\HistoricalInvoiceCorrectionService;
use App\Services\InvoiceService;
use App\Services\PaymentAccountTransferService;
use App\Services\PaymentRefundService;
use App\Services\PaymentService;
use App\Services\SingleMeterService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Acc09P1ExactMoneyTest extends TestCase
{
    use RefreshDatabase;

    private function account(): Subscriber
    {
        return Subscriber::create(['name' => 'Synthetic P1 account',
            'square_id' => Square::create(['name' => 'P1 test'])->id, 'status' => 'active']);
    }

    private function supervisor(): User
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $actor;
    }

    private function balance(Subscriber $subscriber): int
    {
        return app(TransactionService::class)->accountBalanceMinor($subscriber);
    }

    private function refund(Payment $payment, User $actor, string $amount, string $reference): \App\Models\PaymentRefund
    {
        return app(PaymentRefundService::class)->record($payment, $actor, $amount, $reference,
            'treasury_cash', now()->toDateString(), 'Synthetic verified excess credit', 'Synthetic verified independent evidence');
    }

    private function transfer(Payment $payment, Subscriber $to, User $actor): \App\Models\PaymentAccountTransfer
    {
        return app(PaymentAccountTransferService::class)->transfer($payment, $to->id, $actor,
            'Synthetic incorrect subscriber allocation', 'Synthetic verified payer identity evidence');
    }

    private function rejected(callable $action, string $field): void
    {
        try {
            $action();
            self::fail('Expected validation rejection.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_p1_fractional_historical_correction_preserves_each_rate_receipt_and_original_readings(): void
    {
        $subscriber = $this->account();
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'P1-HISTORY']);
        Tariff::create(['name' => 'P1 January', 'unit_price' => '0.50', 'start_date' => '2026-01-01',
            'end_date' => '2026-01-31', 'is_active' => true]);
        Tariff::create(['name' => 'P1 February', 'unit_price' => '1.50', 'start_date' => '2026-02-01', 'is_active' => true]);
        $first = $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'previous_reading' => '0.00', 'current_reading' => '0.01']);
        $second = $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-03-01',
            'period_start' => '2026-02-01', 'period_end' => '2026-03-01', 'previous_reading' => '0.01', 'current_reading' => '0.02']);
        $invoice = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        self::assertSame('0.03', $invoice->total_amount);
        $payment = app(PaymentService::class)->collectPayment($subscriber, '0.02', $invoice->id);
        app(SingleMeterService::class)->replace($meter, ['unique_code' => 'P1-HISTORY-NEW'],
            '2026-03-02', 'Synthetic replacement after billing');
        $originalLines = DB::table('invoice_tariff_lines')->where('invoice_id', $invoice->id)->orderBy('reading_id')->get()->toArray();
        $actor = $this->supervisor();
        $payload = [['reading_id' => $first->id, 'corrected_units' => '0.03'],
            ['reading_id' => $second->id, 'corrected_units' => '0.03']];
        $service = app(HistoricalInvoiceCorrectionService::class);
        $reason = 'Synthetic evidenced historical correction';
        $evidence = 'Synthetic measured historical source evidence';
        $before = $this->balance($subscriber);
        self::assertSame(-1, $before);
        $correction = $service->correctReadings($invoice->fresh(), $actor, $payload, $reason, $evidence);
        $replacement = Invoice::findOrFail($correction->replacement_invoice_id);
        self::assertSame('0.06', $correction->corrected_units);
        self::assertSame('0.07', $correction->corrected_amount);
        self::assertNull($correction->historical_unit_price);
        self::assertSame('0.07', $replacement->total_amount);
        self::assertSame('0.02', $replacement->amount_paid);
        self::assertSame(-5, $this->balance($subscriber));
        self::assertSame('cancelled', $invoice->fresh()->status);
        self::assertSame('0.03', $invoice->fresh()->total_amount);
        self::assertSame($invoice->id, $first->fresh()->invoice_id);
        self::assertSame($invoice->id, $second->fresh()->invoice_id);
        self::assertSame($invoice->id, $payment->fresh()->invoice_id);
        self::assertEquals($originalLines, DB::table('invoice_tariff_lines')->where('invoice_id', $invoice->id)->orderBy('reading_id')->get()->toArray());
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $replacement->id,
            'reading_id' => $first->id, 'unit_price' => '0.50', 'units' => '0.03', 'amount' => '0.02']);
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $replacement->id,
            'reading_id' => $second->id, 'unit_price' => '1.50', 'units' => '0.03', 'amount' => '0.05']);
        self::assertSame($correction->id, $service->correctReadings($invoice->fresh(), $actor,
            array_reverse($payload), $reason, $evidence)->id);
        $this->assertDatabaseCount('historical_invoice_corrections', 1);
        $this->assertDatabaseCount('transactions', 4);
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_p1_historical_correction_overflow_is_rejected_before_any_financial_write(): void
    {
        $subscriber = $this->account();
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'P1-HISTORY-BOUND']);
        Tariff::create(['name' => 'P1 high tariff', 'unit_price' => '10000000.00', 'is_active' => true]);
        $reading = $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'previous_reading' => '0.00', 'current_reading' => '0.01']);
        $invoice = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        app(SingleMeterService::class)->replace($meter, ['unique_code' => 'P1-HISTORY-BOUND-NEW'],
            '2026-02-02', 'Synthetic replacement after billing');
        $actor = $this->supervisor();
        $before = $this->balance($subscriber);
        $revisions = DB::table('payment_allocation_revisions')->count();
        $this->rejected(fn () => app(HistoricalInvoiceCorrectionService::class)->correct($invoice->fresh(), $actor,
            '99999999.99', '10000000.00', 'Synthetic high correction', 'Synthetic verified reading evidence'), 'readings');
        self::assertSame($before, $this->balance($subscriber));
        self::assertNotSame('cancelled', $invoice->fresh()->status);
        self::assertSame($invoice->id, $reading->fresh()->invoice_id);
        self::assertSame($revisions, DB::table('payment_allocation_revisions')->count());
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('historical_invoice_corrections', 0);
    }

    public function test_p1_refund_cumulative_exact_caps_and_replay(): void
    {
        $subscriber = $this->account();
        $actor = $this->supervisor();
        $payment = app(PaymentService::class)->collectPayment($subscriber, '0.31');
        $first = $this->refund($payment, $actor, '0.10', 'P1-REF-1');
        self::assertSame($first->id, $this->refund($payment, $actor, '0.1', 'P1-REF-1')->id);
        $this->refund($payment, $actor, '0.20', 'P1-REF-2');
        self::assertSame(1, $this->balance($subscriber));
        $this->rejected(fn () => $this->refund($payment, $actor, '0.02', 'P1-REF-3'), 'amount');
        self::assertSame(1, $this->balance($subscriber));
        $this->assertDatabaseCount('payment_refunds', 2);
        $this->assertDatabaseCount('transactions', 3);
        $this->refund($payment, $actor, '0.01', 'P1-REF-4');
        self::assertSame(0, $this->balance($subscriber));
        self::assertSame('0.31', $payment->fresh()->amount);
        self::assertNull($payment->fresh()->voided_at);
        $this->assertDatabaseCount('payment_refunds', 3);
        $this->assertDatabaseCount('transactions', 4);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_p1_refund_cannot_use_another_receipt_credit_and_audit_failure_rolls_back(): void
    {
        $subscriber = $this->account();
        $actor = $this->supervisor();
        $service = app(PaymentService::class);
        $receipt = $service->collectPayment($subscriber, '0.10');
        $service->collectPayment($subscriber, '0.50');
        $this->rejected(fn () => $this->refund($receipt, $actor, '0.11', 'P1-REF-OTHER'), 'amount');
        self::assertSame(60, $this->balance($subscriber));
        $before = DB::table('payment_allocation_revisions')->count();
        $this->mock(AuditLogger::class, function ($mock): void {
            $mock->shouldReceive('recordRequired')->once()->with('payment.refunded', \Mockery::type('array'))
                ->andThrow(new \RuntimeException('Synthetic audit failure'));
        });
        try {
            $this->refund($receipt, $actor, '0.10', 'P1-REF-ROLLBACK');
            self::fail('Expected audit failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic audit failure', $e->getMessage());
        }
        self::assertSame(60, $this->balance($subscriber));
        self::assertSame($before, DB::table('payment_allocation_revisions')->count());
        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_p1_transfer_exact_signed_deltas_and_replay_without_new_cash(): void
    {
        $from = $this->account();
        $to = $this->account();
        $actor = $this->supervisor();
        $service = app(PaymentService::class);
        $receipt = $service->collectPayment($from, '0.03');
        $service->collectPayment($to, '0.02');
        $transfer = $this->transfer($receipt, $to, $actor);
        self::assertSame($transfer->id, $this->transfer($receipt, $to, $actor)->id);
        self::assertSame('0.03', $transfer->amount);
        self::assertSame(0, $this->balance($from));
        self::assertSame(5, $this->balance($to));
        self::assertSame($from->id, $receipt->fresh()->subscriber_id);
        self::assertNull($receipt->fresh()->voided_at);
        $this->assertDatabaseHas('transactions', ['type' => 'payment_reversal', 'amount' => '-0.03', 'subscriber_id' => $from->id]);
        $this->assertDatabaseHas('transactions', ['type' => 'payment_transfer_in', 'amount' => '0.03', 'subscriber_id' => $to->id]);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('transactions', 4);
        $this->assertDatabaseCount('payment_account_transfers', 1);
    }

    public function test_p1_transfer_destination_overflow_rolls_back_both_accounts(): void
    {
        $from = $this->account();
        $to = $this->account();
        $actor = $this->supervisor();
        $service = app(PaymentService::class);
        $receipt = $service->collectPayment($from, '0.01');
        $service->collectPayment($to, '9999999999.99');
        $revisions = DB::table('payment_allocation_revisions')->count();
        $this->rejected(fn () => $this->transfer($receipt, $to, $actor), 'subscriber_balance');
        self::assertSame(1, $this->balance($from));
        self::assertSame(999999999999, $this->balance($to));
        self::assertSame('0.01', $from->fresh()->balance);
        self::assertSame('9999999999.99', $to->fresh()->balance);
        self::assertSame($revisions, DB::table('payment_allocation_revisions')->count());
        $this->assertDatabaseCount('payment_account_transfers', 0);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_p1_void_approval_below_equal_above_and_pending_replay_after_setting_change(): void
    {
        $subscriber = $this->account();
        $service = app(PaymentService::class);
        Setting::set('void_approval_threshold', '0.30');
        $below = $service->collectPayment($subscriber, '0.29');
        $equal = $service->collectPayment($subscriber, '0.30');
        $above = $service->collectPayment($subscriber, '0.31');
        self::assertFalse($service->requiresVoidApproval($below));
        self::assertTrue($service->requiresVoidApproval($equal));
        self::assertTrue($service->requiresVoidApproval($above));
        self::assertSame(0.30, $service->voidApprovalThreshold());
        $reason = 'Synthetic documented cancellation request';
        self::assertFalse($service->requestVoid($below, $reason)['pending']);
        self::assertTrue($service->requestVoid($equal, $reason)['pending']);
        self::assertTrue($service->requestVoid($above, $reason)['pending']);
        self::assertSame(61, $this->balance($subscriber));
        Setting::set('void_approval_threshold', '0.00');
        self::assertTrue($service->requestVoid($equal->fresh(), $reason)['pending']);
        self::assertNull($equal->fresh()->voided_at);
        self::assertNotNull($equal->fresh()->void_requested_at);
        self::assertSame(61, $this->balance($subscriber));
        $this->assertDatabaseCount('transactions', 4);
    }

    public function test_p1_invalid_void_threshold_does_not_disable_approval_or_write(): void
    {
        $subscriber = $this->account();
        $service = app(PaymentService::class);
        $receipt = $service->collectPayment($subscriber, '0.30');
        foreach (['0.001', '-1', 'bad', '10000000000.00'] as $invalid) {
            Setting::set('void_approval_threshold', $invalid);
            $this->rejected(fn () => $service->requestVoid($receipt, 'Synthetic documented cancellation request'), 'void_approval_threshold');
            self::assertNull($receipt->fresh()->voided_at);
            self::assertNull($receipt->fresh()->void_requested_at);
            self::assertSame(30, $this->balance($subscriber));
        }
        $this->assertDatabaseCount('transactions', 1);
    }

    /** Capture only ordinary synthetic financial state; no protected rows are modified. */
    private function financialState(): array
    {
        $state = [];
        foreach (['subscribers', 'meters', 'invoices', 'invoice_items', 'invoice_tariff_lines',
            'payments', 'transactions', 'readings', 'payment_allocation_revisions', 'payment_allocations',
            'historical_invoice_corrections', 'payment_refunds', 'payment_account_transfers', 'audit_events'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return $state;
    }

    private function historicalFixture(bool $withPayment = false): array
    {
        $subscriber = $this->account();
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'P1-CORRECTION-OLD']);
        Tariff::create(['name' => 'P1 correction tariff', 'unit_price' => '1.00', 'is_active' => true]);
        $reading = $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'previous_reading' => '0.00', 'current_reading' => '0.01']);
        $invoice = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        $receipt = $withPayment ? app(PaymentService::class)->collectPayment($subscriber, '0.04', $invoice->id) : null;
        $replacement = app(SingleMeterService::class)->replace($meter, ['unique_code' => 'P1-CORRECTION-NEW'],
            '2026-02-02', 'Synthetic replacement after billing')['current_meter'];

        return [$subscriber, $reading, $invoice->fresh(), $replacement, $receipt?->fresh(), $meter->id];
    }

    private function correct(Invoice $invoice, User $actor, string $units = '0.02'): \App\Models\HistoricalInvoiceCorrection
    {
        return app(HistoricalInvoiceCorrectionService::class)->correct($invoice, $actor, $units, '1.00',
            'Synthetic evidenced historical correction', 'Synthetic verified original snapshot evidence');
    }

    public function test_p1_corrections_refund_valid_retired_receipt_keeps_original_identity(): void
    {
        [$subscriber, $reading, $invoice, $current, $receipt, $retiredId] = $this->historicalFixture(true);
        $actor = $this->supervisor();
        self::assertNull($receipt->meter_id);
        self::assertSame($retiredId, (int) $receipt->retired_meter_id);
        $original = Transaction::where('reference_type', Payment::class)->where('reference_id', $receipt->id)->sole();
        self::assertSame($retiredId, (int) $original->retired_meter_id);
        self::assertSame(3, $this->balance($subscriber));
        $refund = $this->refund($receipt, $actor, '0.03', 'P1-ARCHIVED-REFUND');
        self::assertSame($refund->id, $this->refund($receipt, $actor, '0.03', 'P1-ARCHIVED-REFUND')->id);
        self::assertSame(0, $this->balance($subscriber));
        self::assertSame('0.01', $invoice->fresh()->amount_paid);
        self::assertSame($retiredId, (int) $receipt->fresh()->retired_meter_id);
        self::assertSame($retiredId, (int) $original->fresh()->retired_meter_id);
        self::assertSame($invoice->id, $reading->fresh()->invoice_id);
        self::assertNotNull($current->fresh());
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('transactions', 3);
    }

    public function test_p1_corrections_historical_audit_failure_rolls_back_all_records(): void
    {
        [$subscriber, , $invoice] = $this->historicalFixture(true);
        $actor = $this->supervisor();
        $before = $this->financialState();
        $this->mock(AuditLogger::class, function ($mock): void {
            $mock->shouldReceive('recordRequired')->once()->with('invoice.historical_corrected', \Mockery::type('array'))
                ->andThrow(new \RuntimeException('Synthetic historical audit failure'));
        });
        try {
            $this->correct($invoice, $actor);
            self::fail('Expected audit failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic historical audit failure', $e->getMessage());
        }
        self::assertEquals($before, $this->financialState());
        self::assertSame(3, $this->balance($subscriber));
    }

    public function test_p1_corrections_historical_previous_balance_overflow_rolls_back_reversal(): void
    {
        [$subscriber, , $invoice, $meter] = $this->historicalFixture();
        // A synthetic general-account invoice representing an existing historical debt.
        // The general and current-meter caches each fit; their unpaid invoice sum does not.
        $other = Invoice::create(['subscriber_id' => $subscriber->id, 'meter_id' => null,
            'invoice_type' => 'meter', 'bill_number' => 'P1-BOUND-GENERAL',
            'bill_date' => '2026-02-02', 'consumption_fee' => '9999999999.98',
            'total_amount' => '9999999999.98', 'amount_paid' => 0, 'status' => 'issued']);
        app(TransactionService::class)->recordTransaction($subscriber, 'invoice', '-9999999999.98', reference: $other);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-03-01',
            'period_start' => '2026-02-02', 'period_end' => '2026-03-01', 'previous_reading' => '0.00', 'current_reading' => '0.02']);
        app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        $actor = $this->supervisor();
        $before = $this->financialState();
        self::assertSame(-1000000000001, $this->balance($subscriber));
        $this->rejected(fn () => $this->correct($invoice, $actor), 'previous_balance');
        self::assertEquals($before, $this->financialState());
        self::assertSame(-1000000000001, $this->balance($subscriber));
    }

    public function test_p1_corrections_intermediate_cache_overflow_rejects_even_if_final_balance_fits(): void
    {
        [$subscriber, , $invoice] = $this->historicalFixture();
        $payments = app(PaymentService::class);
        $payments->collectPayment($subscriber, '9999999999.99');
        $payments->collectPayment($subscriber, '0.01');
        $actor = $this->supervisor();
        self::assertSame(999999999999, $this->balance($subscriber));
        $before = $this->financialState();
        // The reversal alone would exceed the cache before the replacement
        // restores the final amount. Preserve the current fail-closed policy.
        $this->rejected(fn () => $this->correct($invoice, $actor, '0.01'), 'subscriber_balance');
        self::assertEquals($before, $this->financialState());
        self::assertSame(999999999999, $this->balance($subscriber));
    }

    public function test_p1_corrections_transfer_audit_failure_rolls_back_both_accounts(): void
    {
        $from = $this->account();
        $to = $this->account();
        $actor = $this->supervisor();
        $receipt = app(PaymentService::class)->collectPayment($from, '0.03');
        $before = $this->financialState();
        $this->mock(AuditLogger::class, function ($mock): void {
            $mock->shouldReceive('recordRequired')->once()->with('payment.account_transferred', \Mockery::type('array'))
                ->andThrow(new \RuntimeException('Synthetic transfer audit failure'));
        });
        try {
            $this->transfer($receipt, $to, $actor);
            self::fail('Expected audit failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic transfer audit failure', $e->getMessage());
        }
        self::assertEquals($before, $this->financialState());
        self::assertSame(3, $this->balance($from));
        self::assertSame(0, $this->balance($to));
    }

    public function test_p1_corrections_conflicting_transfer_replay_preserves_first_destination(): void
    {
        $from = $this->account();
        $to = $this->account();
        $different = $this->account();
        $actor = $this->supervisor();
        $receipt = app(PaymentService::class)->collectPayment($from, '0.03');
        $transfer = $this->transfer($receipt, $to, $actor);
        $before = $this->financialState();
        try {
            $this->transfer($receipt, $different, $actor);
            self::fail('Expected conflicting transfer rejection.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        self::assertEquals($before, $this->financialState());
        self::assertSame($transfer->id, $this->transfer($receipt, $to, $actor)->id);
        self::assertSame(3, $this->balance($to));
        self::assertSame(0, $this->balance($different));
    }

    public function test_p1_corrections_zero_threshold_allows_new_request_but_not_pending_replay(): void
    {
        $subscriber = $this->account();
        $service = app(PaymentService::class);
        Setting::set('void_approval_threshold', '0.01');
        $pending = $service->collectPayment($subscriber, '0.01');
        $other = $service->collectPayment($subscriber, '0.02');
        $reason = 'Synthetic documented cancellation request';
        self::assertTrue($service->requestVoid($pending, $reason)['pending']);
        Setting::set('void_approval_threshold', '0.00');
        self::assertFalse($service->requestVoid($other, $reason)['pending']);
        self::assertNotNull($other->fresh()->voided_at);
        self::assertTrue($service->requestVoid($pending->fresh(), $reason)['pending']);
        self::assertNull($pending->fresh()->voided_at);
        self::assertSame(1, $this->balance($subscriber));
    }
}
