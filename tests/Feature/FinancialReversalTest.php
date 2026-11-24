<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\InvoiceCancellationService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialReversalTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $subscriber = Subscriber::create([
            'name' => 'Synthetic reversal account',
            'square_id' => Square::create(['name' => 'Reversal test'])->id,
            'status' => 'active',
        ]);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'ACC05-'.$subscriber->id]);
        $reading = Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01',
            'previous_reading' => 0, 'current_reading' => 10,
        ]);
        $invoice = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        return [$subscriber, $meter, $reading, $invoice];
    }

    public function test_receipt_reversal_is_append_only_idempotent_and_restores_debt(): void
    {
        [$subscriber, , , $invoice] = $this->fixture();
        $payments = app(PaymentService::class);
        $payment = $payments->collectPayment($subscriber, 1500.25, $invoice->id);
        $original = Transaction::where('reference_type', Payment::class)->where('reference_id', $payment->id)->firstOrFail();
        $payments->voidPayment($payment, 'Synthetic duplicate receipt correction');
        $payments->voidPayment($payment, 'Repeated cancellation request');
        $this->assertFalse($original->fresh()->trashed());
        $this->assertSame('1500.25', $original->fresh()->amount);
        $this->assertDatabaseHas('transactions', [
            'reference_type' => Payment::class, 'reference_id' => $payment->id,
            'type' => 'payment_reversal', 'amount' => -1500.25, 'deleted_at' => null,
        ]);
        $this->assertSame(3, Transaction::count());
        $this->assertSame('0.00', $invoice->fresh()->amount_paid);
        $this->assertSame(6000.0, $subscriber->getAccountDebt());
        $this->assertDatabaseHas('audit_events', ['event' => 'payment.voided', 'entity_id' => (string) $payment->id]);
    }

    public function test_invoice_reversal_retains_receipt_and_releases_readings_for_rebilling(): void
    {
        [$subscriber, $meter, $reading, $invoice] = $this->fixture();
        $payment = app(PaymentService::class)->collectPayment($subscriber, 2000, $invoice->id);
        $service = app(InvoiceCancellationService::class);
        $service->cancel($invoice, 'Synthetic incorrect billing correction');
        $service->cancel($invoice, 'Repeated invoice cancellation');
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('6000.00', $invoice->fresh()->total_amount);
        $this->assertSame(0.0, $invoice->fresh()->getCollectibleAmount());
        $this->assertSame($invoice->id, $payment->fresh()->invoice_id);
        $this->assertFalse($reading->fresh()->is_invoiced);
        $this->assertNull($reading->fresh()->invoice_id);
        $this->assertSame(2000.0, $subscriber->getAccountCredit());
        $this->assertSame(3, Transaction::count());
        $this->assertDatabaseHas('transactions', ['reference_type' => Invoice::class, 'reference_id' => $invoice->id, 'type' => 'invoice_reversal', 'amount' => 6000]);
        $this->assertDatabaseHas('audit_events', ['event' => 'invoice.cancelled', 'entity_id' => (string) $invoice->id]);

        $replacement = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assertSame('2000.00', $replacement->amount_paid);
        $this->assertSame(4000.0, $subscriber->getAccountDebt());
        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    public function test_closed_cash_session_cannot_be_rewritten_by_void(): void
    {
        [$subscriber, , , $invoice] = $this->fixture();
        $user = User::factory()->create();
        $cash = app(CashSessionService::class);
        $session = $cash->open($user);
        $payment = app(PaymentService::class)->collectPayment($subscriber, 1000, $invoice->id, userId: $user->id);
        $cash->close($session, 1000);
        try {
            app(PaymentService::class)->voidPayment($payment, 'Synthetic closed till correction');
            $this->fail('Closed till was rewritten');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cash_session', $exception->errors());
        }
        $this->assertNull($payment->fresh()->voided_at);
        $this->assertSame('1000.00', $session->fresh()->expected_amount);
        $this->assertSame(5000.0, $subscriber->getAccountDebt());
        $this->assertSame(2, Transaction::count());
    }

    public function test_direct_delete_and_restore_of_posted_documents_are_rejected(): void
    {
        [$subscriber, , , $invoice] = $this->fixture();
        $payment = app(PaymentService::class)->collectPayment($subscriber, 100);
        foreach ([$invoice, $payment] as $document) {
            foreach (['delete', 'forceDelete'] as $method) {
                try {
                    $document->{$method}();
                    $this->fail('Posted document deletion was allowed');
                } catch (ValidationException $exception) {
                    $this->assertNotEmpty($exception->errors());
                }
            }
        }
        $this->assertSame(5900.0, $subscriber->getAccountDebt());
        $this->assertSame(2, Transaction::count());
    }
}
