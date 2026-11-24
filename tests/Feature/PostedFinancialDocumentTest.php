<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PostedFinancialDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function meter(): Meter
    {
        $subscriber = Subscriber::create([
            'name' => 'Synthetic financial account',
            'square_id' => Square::create(['name' => 'Financial test'])->id,
            'status' => 'active',
        ]);
        return Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'ACC03-'.$subscriber->id]);
    }

    private function postInvoice(Meter $meter): Invoice
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $response = $this->postJson('/api/invoices', [
            'subscriber_id' => $meter->subscriber_id, 'meter_id' => $meter->id,
            'consumption_fee' => 600, 'total_amount' => 9000,
            'previous_balance' => 8000, 'credit_applied' => 5000,
        ])->assertCreated();
        return Invoice::findOrFail($response->json('invoice.id'));
    }

    public function test_posted_invoice_is_immutable_and_server_derives_total(): void
    {
        $meter = $this->meter();
        $invoice = $this->postInvoice($meter);
        $this->assertSame('600.00', $invoice->total_amount);
        $this->assertSame('0.00', $invoice->previous_balance);
        $this->assertSame('0.00', $invoice->credit_applied);
        $this->putJson('/api/invoices/'.$invoice->id, ['total_amount' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('total_amount');
        $this->postJson('/api/invoices/'.$invoice->id.'/recalculate')
            ->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->putJson('/api/invoices/'.$invoice->id, ['notes' => 'Administrative note'])->assertOk();
        $this->assertSame('600.00', $invoice->fresh()->total_amount);
        $this->assertSame('Administrative note', $invoice->fresh()->notes);
        $this->assertSame(600.0, $meter->subscriber->getAccountDebt());
        $this->assertDatabaseCount('transactions', 1);

        try {
            $invoice->fresh()->update(['amount_paid' => 600]);
            $this->fail('Direct model mutation was allowed');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_paid', $exception->errors());
        }
        $this->assertSame('0.00', $invoice->fresh()->amount_paid);
    }

    public function test_posted_receipt_rejects_amount_date_and_owner_changes_but_allows_notes_and_void(): void
    {
        $meter = $this->meter();
        $invoice = $this->postInvoice($meter);
        $payment = app(PaymentService::class)->collectPayment($meter->subscriber, 250, $invoice->id);
        $this->putJson('/api/payments/'.$payment->id, ['amount' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->putJson('/api/payments/'.$payment->id, ['payment_date' => '2020-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_date');
        $this->putJson('/api/payments/'.$payment->id, ['notes' => 'Administrative note'])->assertOk();
        $this->assertSame('250.00', $payment->fresh()->amount);
        $this->assertSame('250.00', $invoice->fresh()->amount_paid);
        $this->assertSame(350.0, $meter->subscriber->getAccountDebt());
        $this->assertDatabaseCount('transactions', 2);
        try {
            $payment->fresh()->update(['subscriber_id' => $this->meter()->subscriber_id]);
            $this->fail('Direct owner mutation was allowed');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subscriber_id', $exception->errors());
        }

        app(PaymentService::class)->voidPayment($payment, 'Synthetic correction of receipt');
        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame('250.00', $payment->fresh()->amount);
        $this->assertSame('0.00', $invoice->fresh()->amount_paid);
        $this->assertSame(600.0, $meter->subscriber->getAccountDebt());
    }

    public function test_unposted_draft_edit_does_not_create_ledger_entry(): void
    {
        $meter = $this->meter();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $draft = Invoice::create([
            'subscriber_id' => $meter->subscriber_id,
            'meter_id' => $meter->id,
            'bill_number' => 'ACC03-DRAFT',
            'bill_date' => '2026-01-01',
            'invoice_type' => 'meter',
            'status' => 'draft',
            'consumption_fee' => 100,
            'total_amount' => 100,
        ]);
        $this->putJson('/api/invoices/'.$draft->id, ['consumption_fee' => 200])->assertOk();
        $this->assertSame('200.00', $draft->fresh()->total_amount);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(0.0, $meter->subscriber->getAccountBalance());
    }
}
