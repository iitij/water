<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\Transaction;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class Acc09CanonicalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $price): array
    {
        $subscriber = Subscriber::create(['name' => 'Synthetic exact money',
            'square_id' => Square::create(['name' => 'Exact money'])->id, 'status' => 'active']);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'EXACT-09']);
        Tariff::create(['name' => 'Synthetic exact tariff', 'unit_price' => $price, 'is_active' => true]);

        return [$subscriber, $meter];
    }

    private function read(Meter $meter, string $start, string $end, string $previous, string $current): \App\Models\Reading
    {
        return $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => $end,
            'period_start' => $start, 'period_end' => $end,
            'previous_reading' => $previous, 'current_reading' => $current]);
    }

    public function test_half_cent_lines_conserve_invoice_payment_allocation_and_reversal(): void
    {
        [$subscriber, $meter] = $this->account('0.50');
        $this->read($meter, '2026-01-01', '2026-01-31', '0.00', '0.01');
        $this->read($meter, '2026-01-31', '2026-02-28', '0.01', '0.02');
        $invoice = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        self::assertSame('0.02', $invoice->total_amount);
        self::assertSame('-0.02', $meter->fresh()->balance);
        self::assertSame(-2, app(TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertDatabaseCount('invoice_tariff_lines', 2);
        $uuid = 'a7770000-1111-4222-8333-444444444444';
        $service = app(PaymentService::class);
        $payment = $service->collectPayment($subscriber, '0.0300', $invoice->id, syncUuid: $uuid);
        $replayed = $service->collectPayment($subscriber, 0.03, $invoice->id, syncUuid: $uuid);
        self::assertSame($payment->id, $replayed->id);
        self::assertSame('0.03', $payment->amount);
        self::assertSame('0.02', $invoice->fresh()->amount_paid);
        self::assertSame('0.01', $meter->fresh()->balance);
        self::assertSame(1, app(TransactionService::class)->accountBalanceMinor($subscriber));
        $revision = DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)->orderByDesc('revision')->first();
        self::assertSame(3, (int) $revision->available_minor);
        self::assertSame(2, (int) $revision->allocated_minor);
        self::assertSame(1, (int) $revision->unallocated_minor);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 2);
        $service->voidPayment($payment, 'Synthetic exact reversal');
        self::assertSame('0.00', $invoice->fresh()->amount_paid);
        self::assertSame('-0.02', $meter->fresh()->balance);
        self::assertSame(-2, app(TransactionService::class)->accountBalanceMinor($subscriber));
        $this->assertDatabaseCount('transactions', 3);
    }

    public function test_non_cent_input_rejected_before_issuance(): void
    {
        [$subscriber] = $this->account('1.00');
        foreach (['0.001', '1.000000000000001', '10000000000.00', '-1.00', 0.1 + 0.2] as $amount) {
            try {
                app(PaymentService::class)->collectPayment($subscriber, $amount);
                self::fail('Non-cent or out-of-range payment was accepted.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('amount', $e->errors());
            }
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('payment_allocation_revisions', 0);
    }

    public function test_narrow_meter_cache_overflow_rolls_back_invoice_and_reading_claim(): void
    {
        [$subscriber, $meter] = $this->account('99999999.99');
        $reading = $this->read($meter, '2026-01-01', '2026-01-31', '0.00', '2.00');
        try {
            app(InvoiceService::class)->generateInvoice($subscriber, $meter);
            self::fail('Invoice should not exceed meter balance storage capacity.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('meter_balance', $e->errors());
        }
        self::assertFalse((bool) $reading->fresh()->is_invoiced);
        self::assertNull($reading->fresh()->invoice_id);
        self::assertSame('0.00', $meter->fresh()->balance);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_tariff_lines', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('payment_allocation_revisions', 0);
    }

    public function test_narrow_meter_cache_overflow_rolls_back_payment_allocation_and_ledger(): void
    {
        [$subscriber, $meter] = $this->account('1.00');
        try {
            app(PaymentService::class)->collectPayment($subscriber, '100000000.00', requestedMeterId: $meter->id);
            self::fail('Receipt should not exceed meter balance storage capacity.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('meter_balance', $e->errors());
        }
        self::assertSame('0.00', $meter->fresh()->balance);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('payment_allocation_revisions', 0);
    }

    public function test_individual_receipt_limit_and_aggregate_conservation(): void
    {
        [$subscriber] = $this->account('1.00');
        $service = app(PaymentService::class);
        $first = $service->collectPayment($subscriber, '9999999999.99');
        self::assertSame('9999999999.99', $first->amount);
        try {
            $service->collectPayment($subscriber, '0.01');
            self::fail('General balance storage must not overflow.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('subscriber_balance', $e->errors());
        }
        self::assertSame(999999999999, app(TransactionService::class)->accountBalanceMinor($subscriber));
        self::assertSame('9999999999.99', $subscriber->fresh()->balance);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 1);
    }
}
