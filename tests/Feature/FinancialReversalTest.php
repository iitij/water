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

    public function test_missing_original_posting_cannot_create_a_reversal(): void
    {
        [$subscriber, , , $invoice] = $this->fixture();
        $payment = Payment::create([
            'subscriber_id' => $subscriber->id,
            'receipt_number' => 'UNPOSTED-ACC05',
            'amount' => 1000,
            'payment_date' => now(),
            'payment_method' => 'cash',
        ]);
        try {
            app(PaymentService::class)->voidPayment($payment, 'Invalid missing ledger posting');
            $this->fail('Reversal without original was allowed');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transaction', $exception->errors());
        }
        $this->assertNull($payment->fresh()->voided_at);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(6000.0, $subscriber->getAccountDebt());
        $this->assertDatabaseMissing('audit_events', ['event' => 'payment.voided', 'entity_id' => (string) $payment->id]);
    }

    public function test_cancelled_invoice_cannot_receive_a_targeted_payment(): void
    {
        [$subscriber, , , $invoice] = $this->fixture();
        app(InvoiceCancellationService::class)->cancel($invoice, 'Synthetic incorrect charge correction');
        try {
            app(PaymentService::class)->collectPayment($subscriber, 1000, $invoice->id);
            $this->fail('Targeted payment to cancelled invoice was allowed');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->assertSame(Invoice::class, $exception->getModel());
        }
        $this->assertSame(0, Payment::count());
        $this->assertSame(2, Transaction::count());
    }


    public function test_reversal_rolls_back_if_required_audit_cannot_be_written(): void
    {
        [$subscriber, , , $invoice] = $this->fixture();
        $payment = app(PaymentService::class)->collectPayment($subscriber, 1000, $invoice->id);
        $this->mock(\App\Services\AuditLogger::class, function ($mock) {
            $mock->shouldReceive('recordRequired')->with('payment.voided', \Mockery::type('array'))
                ->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        });
        try {
            app(PaymentService::class)->voidPayment($payment, 'Synthetic audit rollback scenario');
            $this->fail('Unaudited financial reversal was committed');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertNull($payment->fresh()->voided_at);
        $this->assertSame('1000.00', $invoice->fresh()->amount_paid);
        $this->assertSame(5000.0, $subscriber->getAccountDebt());
        $this->assertSame(2, Transaction::count());
    }


    public function test_approved_till_duplicate_correction_preserves_close_and_is_idempotent(): void
    {
        [$subscriber, , , $invoice] = $this->fixture();
        $collector = User::factory()->create(['role' => 'collector']);
        $supervisor = User::factory()->create(['role' => 'admin']);
        $supervisor->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $cash = app(CashSessionService::class);
        $session = $cash->open($collector);
        $service = app(PaymentService::class);
        $original = $service->collectPayment($subscriber, 1000, $invoice->id, userId: $collector->id);
        $duplicate = $service->collectPayment($subscriber, 1000, $invoice->id, userId: $collector->id);
        $session = $cash->close($session, 1000);
        $session = $cash->decide($session, true, $supervisor);
        $before = $cash->summary($session);
        $this->actingAs($supervisor, 'sanctum');
        $payload = [
            'payment_id' => $duplicate->id,
            'duplicate_of_id' => $original->id,
            'reason' => 'Synthetic duplicate recording correction',
            'evidence' => 'Synthetic proof only one physical receipt occurred',
            'no_second_cash_receipt' => true,
        ];
        $url = '/api/cash-sessions/'.$session->id.'/correct-duplicate';
        $this->postJson($url, array_replace($payload, ['no_second_cash_receipt' => false]))->assertUnprocessable();
        $id = $this->postJson($url, $payload)->assertOk()->json('correction.id');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('correction.id', $id);
        $this->assertDatabaseCount('closed_cash_corrections', 1);
        $this->assertDatabaseCount('transactions', 4);
        $this->assertNull($original->fresh()->voided_at);
        $this->assertNotNull($duplicate->fresh()->voided_at);
        $this->assertSame('1000.00', $invoice->fresh()->amount_paid);
        $this->assertSame(5000.0, $subscriber->getAccountDebt());
        $after = $cash->summary($session->fresh());
        foreach (['expected_amount', 'counted_amount', 'variance_amount', 'receipts', 'voided_receipts', 'by_method', 'state'] as $key) {
            $this->assertSame($before[$key], $after[$key], $key);
        }
        $this->assertSame('1000.00', $after['post_close_correction_total']);
        $this->assertDatabaseHas('audit_events', ['event' => 'cash_session.duplicate_corrected', 'entity_id' => (string) $session->id]);
    }


    public function test_replacing_billed_meter_preserves_readings_receipts_and_debt_without_second_meter(): void
    {
        [$subscriber, $old, $reading, $invoice] = $this->fixture();
        $payment = app(PaymentService::class)->collectPayment($subscriber, 2000, $invoice->id);
        $replacement = app(\App\Services\SingleMeterService::class)->replace($old, [
            'unique_code' => 'ACC05-REPLACEMENT',
            'initial_reading' => 100,
        ], '2026-02-02', 'Synthetic replacement after billing')['current_meter'];

        $this->assertNotSame($old->id, $replacement->id);
        $this->assertSame(1, Meter::withTrashed()->where('subscriber_id', $subscriber->id)->count());
        $this->assertDatabaseMissing('meters', ['id' => $old->id]);
        $this->assertDatabaseHas('readings', ['id' => $reading->id, 'invoice_id' => $invoice->id,
            'meter_id' => null, 'retired_meter_id' => $old->id, 'retired_meter_code' => $old->unique_code]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'invoice_id' => $invoice->id,
            'meter_id' => null, 'retired_meter_id' => $old->id]);
        $this->assertSame('6000.00', $invoice->fresh()->total_amount);
        $this->assertSame('2000.00', $invoice->fresh()->amount_paid);
        $this->assertSame(4000.0, $subscriber->getAccountDebt());
        $this->assertSame(100.0, $replacement->getLastReadingValue());

        Reading::create([
            'meter_id' => $replacement->id, 'reading_date' => '2026-02-28',
            'period_start' => '2026-02-02', 'period_end' => '2026-02-28',
            'previous_reading' => 100, 'current_reading' => 105,
        ]);
        $next = app(InvoiceService::class)->generateInvoice($subscriber, $replacement);
        $this->assertSame('3000.00', $next->total_amount);
        $this->assertSame('4000.00', $next->previous_balance);
        $this->assertSame(7000.0, $subscriber->getAccountDebt());
        $this->assertSame(3, Transaction::count());

        app(PaymentService::class)->voidPayment($payment, 'Synthetic old receipt reversal');
        $this->assertSame(9000.0, $subscriber->getAccountDebt());
        $this->assertDatabaseHas('transactions', ['reference_type' => Payment::class, 'reference_id' => $payment->id,
            'type' => 'payment_reversal', 'amount' => -2000, 'retired_meter_id' => $old->id]);
    }


    public function test_wrong_subscriber_transfer_keeps_original_cash_and_moves_allocation_once(): void
    {
        [$from, , , $fromInvoice] = $this->fixture();
        $to = Subscriber::create([
            'name' => 'Correct synthetic account', 'square_id' => $from->square_id, 'status' => 'active',
        ]);
        $toMeter = Meter::create(['subscriber_id' => $to->id, 'unique_code' => 'ACC05-TRANSFER-TO']);
        Reading::create([
            'meter_id' => $toMeter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01',
            'previous_reading' => 0, 'current_reading' => 5,
        ]);
        $toInvoice = app(InvoiceService::class)->generateInvoice($to, $toMeter);
        $collector = User::factory()->create(['role' => 'collector']);
        $supervisor = User::factory()->create(['role' => 'admin']);
        $supervisor->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $cash = app(CashSessionService::class);
        $session = $cash->open($collector);
        $payment = app(PaymentService::class)->collectPayment($from, 1500.25, $fromInvoice->id, userId: $collector->id);
        $session = $cash->decide($cash->close($session, 1500.25), true, $supervisor);
        $before = $cash->summary($session);
        $this->assertSame(4499.75, $from->getAccountDebt());
        $this->assertSame(3000.0, $to->getAccountDebt());
        $payload = [
            'to_subscriber_id' => $to->id,
            'reason' => 'Synthetic incorrect subscriber allocation',
            'evidence' => 'Synthetic verified identity of actual payer',
            'no_new_cash_movement' => true,
        ];
        $url = '/api/payments/'.$payment->id.'/transfer-account';
        $this->actingAs($collector, 'sanctum')->postJson($url, $payload)->assertForbidden();
        $this->actingAs($supervisor, 'sanctum');
        $this->postJson($url, array_replace($payload, ['no_new_cash_movement' => false]))->assertUnprocessable();
        $id = $this->postJson($url, $payload)->assertOk()->json('transfer.id');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('transfer.id', $id);
        $this->assertDatabaseCount('payment_account_transfers', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 5);
        $this->assertNull($payment->fresh()->voided_at);
        $this->assertSame($from->id, $payment->fresh()->subscriber_id);
        $this->assertSame('0.00', $fromInvoice->fresh()->amount_paid);
        $this->assertSame('1500.25', $toInvoice->fresh()->amount_paid);
        $this->assertSame(6000.0, $from->getAccountDebt());
        $this->assertSame(1499.75, $to->getAccountDebt());
        $this->assertSame(7499.75, $from->getAccountDebt() + $to->getAccountDebt());
        $this->assertSame(json_encode($before, JSON_THROW_ON_ERROR), json_encode($cash->summary($session->fresh()), JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('audit_events', ['event' => 'payment.account_transferred', 'entity_id' => (string) $id]);
        try {
            app(PaymentService::class)->voidPayment($payment, 'Synthetic disallowed double reversal');
            $this->fail('Transferred receipt was reversed twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
        }
        $this->assertDatabaseCount('transactions', 5);
        app(PaymentService::class)->recalculateInvoices($from);
        app(PaymentService::class)->recalculateInvoices($to);
        $this->assertSame('0.00', $fromInvoice->fresh()->amount_paid);
        $this->assertSame('1500.25', $toInvoice->fresh()->amount_paid);
    }

}
