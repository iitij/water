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

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Tariff::create(['name' => 'Synthetic baseline tariff', 'unit_price' => 600, 'is_active' => true]);
    }

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
        $projection = app(\App\Services\PaymentAllocationReadService::class);
        $snapshot = $projection->forPayment($payment->fresh());
        $this->assertSame($to->id, $snapshot['subscriber_id']);
        $this->assertSame(150025, $snapshot['allocated_minor']);
        $this->assertSame($toInvoice->id, $snapshot['rows'][0]['invoice_id']);
        $this->assertNull($snapshot['rows'][0]['payment_id']);
        $this->assertNotNull($snapshot['rows'][0]['account_transfer_id']);
        $this->assertTrue($projection->reconcile($from)['matches']);
        $this->assertTrue($projection->reconcile($to)['matches']);
        $this->assertSame(7499.75, $from->getAccountDebt() + $to->getAccountDebt());
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('stats_overview.outstanding_amount', 7499.75);
        $this->getJson('/api/admin/reports?from_date='.now()->toDateString().'&to_date='.now()->toDateString())
            ->assertOk()->assertJsonPath('financial_movements.gross_valid_receipts', 1500.25)
            ->assertJsonPath('financial_movements.account_transfers_in', 1500.25)
            ->assertJsonPath('financial_movements.net_receipts_after_refunds', 1500.25);
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


    public function test_refund_preserves_receipt_and_till_and_removes_credit_from_future_billing(): void
    {
        [$subscriber, $meter, , $invoice] = $this->fixture();
        $collector = User::factory()->create(['role' => 'collector']);
        $supervisor = User::factory()->create(['role' => 'admin']);
        $supervisor->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $cash = app(CashSessionService::class);
        $session = $cash->open($collector);
        $payment = app(PaymentService::class)->collectPayment($subscriber, 8000, $invoice->id, userId: $collector->id);
        $session = $cash->decide($cash->close($session, 8000), true, $supervisor);
        $snapshot = json_encode($cash->summary($session), JSON_THROW_ON_ERROR);
        $this->assertSame(2000.0, $subscriber->getAccountCredit());
        $payload = [
            'amount' => '1250.25', 'disbursement_reference' => 'TREASURY-ACC05-01',
            'method' => 'treasury_cash', 'disbursed_on' => now()->toDateString(),
            'reason' => 'Synthetic return of excess credit',
            'evidence' => 'Synthetic signed proof of disbursement',
            'disbursement_confirmed' => true,
        ];
        $url = '/api/payments/'.$payment->id.'/refund';
        $this->actingAs($collector, 'sanctum')->postJson($url, $payload)->assertForbidden();
        $this->actingAs($supervisor, 'sanctum');
        $this->postJson($url, array_replace($payload, ['disbursement_confirmed' => false]))->assertUnprocessable();
        $this->postJson($url, array_replace($payload, ['amount' => '2000.01']))->assertUnprocessable();
        $id = $this->postJson($url, $payload)->assertOk()->json('refund.id');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('refund.id', $id);
        $this->postJson($url, array_replace($payload, ['amount' => '100.00']))->assertConflict();
        $this->postJson($url, array_replace($payload, ['amount' => '750.00', 'disbursement_reference' => 'TREASURY-ACC05-02']))->assertUnprocessable();
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 3);
        $this->assertSame('8000.00', $payment->fresh()->amount);
        $this->assertNull($payment->fresh()->voided_at);
        $this->assertSame('6000.00', $invoice->fresh()->amount_paid);
        $this->assertSame(749.75, $subscriber->getAccountCredit());
        $projection = app(\App\Services\PaymentAllocationReadService::class);
        $allocationSnapshot = $projection->forPayment($payment->fresh());
        $this->assertSame(600000, $allocationSnapshot['allocated_minor']);
        $this->assertSame(74975, $allocationSnapshot['unallocated_minor']);
        $this->assertTrue($projection->reconcile($subscriber)['matches']);
        $this->getJson('/api/admin/reports?from_date='.now()->toDateString().'&to_date='.now()->toDateString())
            ->assertOk()->assertJsonPath('financial_movements.gross_valid_receipts', 8000)
            ->assertJsonPath('financial_movements.refunds_disbursed', 1250.25)
            ->assertJsonPath('financial_movements.net_receipts_after_refunds', 6749.75);
        $this->assertSame($snapshot, json_encode($cash->summary($session->fresh()), JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('transactions', [
            'type' => 'payment_refund', 'reference_type' => \App\Models\PaymentRefund::class,
            'reference_id' => $id, 'amount' => -1250.25,
        ]);
        $this->assertDatabaseHas('audit_events', ['event' => 'payment.refunded', 'entity_id' => (string) $id]);

        Reading::create([
            'meter_id' => $meter->id, 'reading_date' => '2026-03-01',
            'period_start' => '2026-02-02', 'period_end' => '2026-03-01',
            'previous_reading' => 10, 'current_reading' => 12,
        ]);
        $next = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        $this->assertSame('749.75', $next->fresh()->amount_paid);
        $this->assertSame(450.25, $subscriber->getAccountDebt());
        $this->assertSame('6000.00', $invoice->fresh()->amount_paid);
        try {
            app(PaymentService::class)->voidPayment($payment, 'Synthetic prohibited cancellation after refund');
            $this->fail('Refunded receipt was voided.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
        }
    }


    public function test_historical_invoice_correction_preserves_archived_readings_and_allocates_original_receipt(): void
    {
        [$subscriber, $old, $reading, $invoice] = $this->fixture();
        $payment = app(PaymentService::class)->collectPayment($subscriber, 2000.25, $invoice->id);
        $new = app(\App\Services\SingleMeterService::class)->replace($old, [
            'unique_code' => 'HIST-CORRECTION-NEW', 'initial_reading' => 100,
        ], '2026-02-02', 'Synthetic meter replacement after billing')['current_meter'];
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $collector = User::factory()->create(['role' => 'collector']);
        $payload = [
            'corrected_units' => '7.50', 'historical_unit_price' => '600.00',
            'reason' => 'Synthetic historical consumption correction',
            'evidence' => 'Synthetic verified archived meter evidence',
        ];
        $url = '/api/invoices/'.$invoice->id.'/correct-historical';
        $this->actingAs($collector, 'sanctum')->postJson($url, $payload)->assertForbidden();
        $this->actingAs($admin, 'sanctum');
        $this->postJson($url, array_replace($payload, ['historical_unit_price' => '800.00']))
            ->assertUnprocessable()->assertJsonValidationErrors('historical_unit_price');
        $this->assertDatabaseCount('historical_invoice_corrections', 0);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertSame(3999.75, $subscriber->getAccountDebt());
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $invoice->id,
            'reading_id' => $reading->id, 'units' => 10, 'unit_price' => 600, 'amount' => 6000]);

        $response = $this->postJson($url, $payload)->assertOk();
        $id = $response->json('correction.id');
        $replacementId = $response->json('correction.replacement_invoice_id');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('correction.id', $id);
        $this->postJson($url, array_replace($payload, ['corrected_units' => '8.00']))->assertConflict();
        $replacement = Invoice::findOrFail($replacementId);
        $this->assertDatabaseCount('historical_invoice_corrections', 1);
        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseCount('transactions', 4);
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('6000.00', $invoice->fresh()->total_amount);
        $this->assertSame('4500.00', $replacement->total_amount);
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $replacementId,
            'reading_id' => $reading->id, 'units' => 7.50, 'unit_price' => 600, 'amount' => 4500]);
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $invoice->id,
            'reading_id' => $reading->id, 'units' => 10, 'unit_price' => 600, 'amount' => 6000]);
        $this->assertSame('2000.25', $replacement->amount_paid);
        $this->assertSame(2499.75, $subscriber->getAccountDebt());
        $this->assertSame($invoice->id, $payment->fresh()->invoice_id);
        $this->assertSame($invoice->id, $reading->fresh()->invoice_id);
        $this->assertTrue($reading->fresh()->is_invoiced);
        $this->assertNull($reading->fresh()->meter_id);
        $this->assertSame($old->id, $replacement->retired_meter_id);
        $this->assertSame($invoice->billing_period_start->toDateString(), $replacement->billing_period_start->toDateString());
        $this->assertSame(1, Meter::withTrashed()->where('subscriber_id', $subscriber->id)->count());
        $this->assertSame(100.0, $new->getLastReadingValue());
        $this->assertDatabaseHas('transactions', ['reference_type' => Invoice::class,
            'reference_id' => $invoice->id, 'type' => 'invoice_reversal', 'amount' => 6000]);
        $this->assertDatabaseHas('audit_events', ['event' => 'invoice.historical_corrected', 'entity_id' => (string) $id]);

        // The replacement must not fall through the normal cancellation path.
        $this->postJson('/api/invoices/'.$replacementId.'/cancel', ['reason' => 'Synthetic unsupported second correction'])->assertUnprocessable();
        Reading::create([
            'meter_id' => $new->id, 'reading_date' => '2026-03-01',
            'period_start' => '2026-02-02', 'period_end' => '2026-03-01',
            'previous_reading' => 100, 'current_reading' => 102,
        ]);
        $next = app(InvoiceService::class)->generateInvoice($subscriber, $new);
        $this->assertSame('1200.00', $next->total_amount);
        $this->assertSame(3699.75, $subscriber->getAccountDebt());
        $this->assertSame($invoice->id, $reading->fresh()->invoice_id);
    }


    public function test_legacy_correction_requires_approved_historical_coverage(): void
    {
        [$subscriber, $old, $reading, $invoice] = $this->fixture();
        // Simulate a pre-ACC-08 invoice with no persisted pricing snapshot.
        \Illuminate\Support\Facades\DB::table('invoice_tariff_lines')->where('invoice_id', $invoice->id)->delete();
        $tariff = \App\Models\Tariff::sole();
        $tariff->update(['is_active' => false]);
        app(\App\Services\SingleMeterService::class)->replace($old, [
            'unique_code' => 'LEGACY-CORRECTION-NEW',
        ], '2026-02-02', 'Synthetic historical meter replacement');
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin, 'sanctum');
        $payload = ['corrected_units' => '8.00', 'historical_unit_price' => '600.00',
            'reason' => 'Synthetic historical correction reason', 'evidence' => 'Synthetic archived reading evidence'];
        $url = '/api/invoices/'.$invoice->id.'/correct-historical';
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('tariff');
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('historical_invoice_corrections', 0);
        $this->assertSame(6000.0, $subscriber->getAccountDebt());

        $tariff->fresh()->update(['is_active' => true]);
        $replacementId = $this->postJson($url, $payload)->assertOk()->json('correction.replacement_invoice_id');
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $replacementId,
            'reading_id' => $reading->id, 'tariff_id' => $tariff->id, 'units' => 8,
            'unit_price' => 600, 'amount' => 4800]);
        $this->assertSame(4800.0, $subscriber->getAccountDebt());
        $this->assertDatabaseMissing('invoice_tariff_lines', ['invoice_id' => $invoice->id]);
    }

    public function test_aggregate_correction_cannot_allocate_units_across_multiple_readings(): void
    {
        $subscriber = Subscriber::create(['name' => 'Synthetic multi-period correction',
            'square_id' => Square::create(['name' => 'Multi-period test'])->id, 'status' => 'active']);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'MULTI-CORRECT']);
        $tariff = \App\Models\Tariff::sole();
        $tariff->update(['end_date' => '2026-01-31']);
        \App\Models\Tariff::create(['name' => 'February tariff', 'unit_price' => 800,
            'start_date' => '2026-02-01', 'is_active' => true]);
        foreach ([['2026-01-01', '2026-02-01', 0, 10], ['2026-02-01', '2026-03-01', 10, 15]] as [$start, $end, $previous, $current]) {
            Reading::create(['meter_id' => $meter->id, 'reading_date' => $end, 'period_start' => $start,
                'period_end' => $end, 'previous_reading' => $previous, 'current_reading' => $current]);
        }
        $invoice = app(InvoiceService::class)->generateInvoice($subscriber, $meter);
        app(\App\Services\SingleMeterService::class)->replace($meter, [
            'unique_code' => 'MULTI-CORRECT-NEW',
        ], '2026-03-02', 'Synthetic historical meter replacement');
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin, 'sanctum')->postJson('/api/invoices/'.$invoice->id.'/correct-historical', [
            'corrected_units' => '12.00', 'historical_unit_price' => '600.00',
            'reason' => 'Synthetic historical correction reason', 'evidence' => 'Synthetic archived reading evidence',
        ])->assertUnprocessable()->assertJsonValidationErrors('corrected_units');
        $this->assertDatabaseCount('historical_invoice_corrections', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('invoice_tariff_lines', 2);
        $this->assertSame(10000.0, $subscriber->getAccountDebt());
    }

    public function test_field_pack_contains_unified_balance_after_meter_billing_and_payment(): void
    {
        [$subscriber, , , $invoice] = $this->fixture();
        app(PaymentService::class)->collectPayment($subscriber, 1250.25, $invoice->id);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/squares/'.$subscriber->square_id.'/offline-pack')
            ->assertOk()->assertJsonPath('subscribers.0.account_balance', -4749.75);
    }


    public function test_queued_invoice_payment_retry_after_cancellation_is_idempotent_and_new_target_is_rejected(): void
    {
        [$subscriber, , , $invoice] = $this->fixture();
        $collector = User::factory()->create(['role' => 'collector']);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $payload = ['amount' => 1000.25, 'payment_method' => 'cash', 'sync_uuid' => $uuid];
        $url = '/api/invoices/'.$invoice->id.'/pay';
        $this->actingAs($collector, 'sanctum');
        $receiptId = $this->postJson($url, $payload)->assertCreated()->json('payment.id');
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/invoices/'.$invoice->id.'/cancel', ['reason' => 'Synthetic cancellation between field retries'])->assertOk();
        $this->actingAs($collector, 'sanctum');
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('payment.id', $receiptId);
        $this->postJson($url, array_replace($payload, ['sync_uuid' => (string) \Illuminate\Support\Str::uuid()]))
            ->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 3);
        $this->assertSame(1000.25, $subscriber->getAccountCredit());
    }


    public function test_posted_ledger_cannot_be_updated_deleted_or_restored_directly(): void
    {
        [$subscriber, , , $invoice] = $this->fixture();
        $payment = app(PaymentService::class)->collectPayment($subscriber, 2000, $invoice->id);
        $entry = Transaction::where('reference_type', Payment::class)->where('reference_id', $payment->id)->firstOrFail();
        foreach ([
            fn () => $entry->fresh()->update(['amount' => 1]),
            fn () => $entry->fresh()->update(['subscriber_id' => 999]),
            fn () => $entry->fresh()->delete(),
            fn () => $entry->fresh()->forceDelete(),
            fn () => $entry->fresh()->restore(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A direct ledger mutation was allowed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('transaction', $exception->errors());
            }
        }
        $this->assertSame('2000.00', $entry->fresh()->amount);
        $this->assertSame(2, Transaction::count());
        $this->assertSame(4000.0, $subscriber->getAccountDebt());
        app(PaymentService::class)->voidPayment($payment, 'Synthetic allowed reversal through accounting service');
        $this->assertSame(3, Transaction::count());
        $this->assertSame(6000.0, $subscriber->getAccountDebt());
    }

}
