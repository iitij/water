<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Tariff;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DocumentIssuanceSequenceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): Meter
    {
        $this->travelTo(now()->setDate(2026, 11, 25)->setTime(12, 0));
        $subscriber = Square::create(['name' => 'Synthetic ACC14'])
            ->subscribers()->create(['name' => 'Synthetic account', 'status' => 'active']);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'ACC14-SYNTH']);
        Tariff::create(['name' => 'Synthetic tariff', 'unit_price' => '600.00', 'is_active' => true, 'start_date' => '2026-01-01']);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01',
            'previous_reading' => 0, 'current_reading' => 10]);

        return $meter;
    }

    public function test_actual_writers_use_independent_sequences_and_replay_does_not_consume_a_number(): void
    {
        $meter = $this->fixture();
        $subscriber = $meter->subscriber;
        foreach (['INV-202611-9999', 'inv-202611-10000'] as $number) {
            (new Invoice())->forceFill(['subscriber_id' => $subscriber->id, 'bill_number' => $number,
                'bill_date' => '2026-11-01', 'status' => 'cancelled', 'consumption_fee' => '0.00',
                'previous_balance' => '0.00', 'total_amount' => '0.00', 'amount_paid' => '0.00',
                'deleted_at' => now()])->saveQuietly();
        }
        $invoice = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assertSame('INV-202611-10001', $invoice->bill_number);
        $this->assertSame(10001, (int) DB::table('document_sequences')->where('kind', 'INV')->value('last_number'));
        $uuid = (string) Str::uuid();
        $service = app(PaymentService::class);
        $first = $service->collectPayment($subscriber, '10.00', $invoice->id, paymentDate: '2026-10-01', syncUuid: $uuid);
        $replay = $service->collectPayment($subscriber, '10.00', $invoice->id, paymentDate: '2026-10-01', syncUuid: $uuid);
        $this->assertSame('REC-202611-0001', $first->receipt_number);
        $this->assertSame($first->id, $replay->id);
        $this->assertSame('2026-10-01', $first->payment_date->toDateString());
        $second = $service->collectPayment($subscriber, '20.00', $invoice->id);
        $this->assertSame('REC-202611-0002', $second->receipt_number);
        $this->assertSame(2, Payment::count());
        $this->assertSame(2, (int) DB::table('document_sequences')->where('kind', 'REC')->value('last_number'));
        $this->assertSame(3, DB::table('transactions')->count());
    }

    public function test_outer_rollback_reverts_reservation_document_and_ledger(): void
    {
        $meter = $this->fixture();
        $subscriber = $meter->subscriber;
        $service = app(InvoiceService::class);
        try {
            DB::transaction(function () use ($subscriber, $meter, $service): void {
                $this->assertSame('INV-202611-0001', $service->generateInvoice($subscriber, $meter)->bill_number);
                throw new \RuntimeException('synthetic invoice rollback');
            });
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic invoice rollback', $e->getMessage());
        }
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, DB::table('transactions')->count());
        $this->assertSame(0, DB::table('document_sequences')->count());
        $invoice = $service->generateInvoice($subscriber, $meter);
        $this->assertSame('INV-202611-0001', $invoice->bill_number);
        try {
            DB::transaction(function () use ($subscriber, $invoice): void {
                $payment = app(PaymentService::class)->collectPayment($subscriber, '1.00', $invoice->id);
                $this->assertSame('REC-202611-0001', $payment->receipt_number);
                throw new \RuntimeException('synthetic receipt rollback');
            });
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic receipt rollback', $e->getMessage());
        }
        $this->assertSame(0, Payment::count());
        $this->assertSame(1, DB::table('transactions')->count());
        $this->assertFalse(DB::table('document_sequences')->where('kind', 'REC')->exists());
        $this->assertSame('REC-202611-0001', app(PaymentService::class)->collectPayment($subscriber, '1.00', $invoice->id)->receipt_number);
    }
}
