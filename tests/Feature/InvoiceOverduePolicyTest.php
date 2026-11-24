<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Square;
use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceOverduePolicyTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function invoice(Subscriber $subscriber, array $attributes = []): Invoice
    {
        $invoice = new Invoice;
        $invoice->forceFill(array_merge([
            'subscriber_id' => $subscriber->id,
            'bill_number' => 'OVERDUE-'.++$this->sequence,
            'bill_date' => '2026-01-01',
            'total_amount' => '100.00',
            'amount_paid' => '40.00',
            'status' => 'partially_paid',
        ], $attributes))->saveQuietly();

        return $invoice;
    }

    private function subscriber(): Subscriber
    {
        return Square::create(['name' => 'Test square'])->subscribers()
            ->create(['name' => 'Test subscriber', 'status' => 'active']);
    }

    public function test_grace_day_is_inclusive_and_partial_debt_becomes_overdue(): void
    {
        $invoice = $this->invoice($this->subscriber());
        Setting::set('grace_period', '14');

        $this->travelTo(now()->setDate(2026, 1, 15)->setTime(23, 59, 59));
        $invoice->updateStatus();
        $this->assertSame('partially_paid', $invoice->fresh()->status);

        $this->travelTo(now()->setDate(2026, 1, 16)->startOfDay());
        $invoice->updateStatus();
        $this->assertSame('overdue', $invoice->fresh()->status);
        $this->assertSame('40.00', $invoice->fresh()->amount_paid);
    }

    public function test_strict_policy_does_not_mark_paid_draft_or_cancelled_invoices_overdue(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
        Setting::set('strict_overdue_on_new_invoice', '1');
        $subscriber = $this->subscriber();
        $old = $this->invoice($subscriber);
        $paid = $this->invoice($subscriber, ['amount_paid' => '100.00', 'status' => 'paid']);
        $latest = $this->invoice($subscriber, ['amount_paid' => '0.00', 'status' => 'issued']);
        $draft = $this->invoice($subscriber, ['status' => 'draft']);
        $cancelled = $this->invoice($subscriber, ['status' => 'cancelled']);

        $this->artisan('accounting:refresh-invoice-statuses')->assertSuccessful();
        $this->assertSame('overdue', $old->fresh()->status);
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame('issued', $latest->fresh()->status);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_refresh_is_idempotent_and_does_not_change_financial_amounts(): void
    {
        $this->travelTo(now()->setDate(2026, 2, 1)->startOfDay());
        $subscriber = $this->subscriber();
        $invoice = $this->invoice($subscriber, ['previous_balance' => '900.00']);
        $deleted = $this->invoice($subscriber, ['deleted_at' => now()]);
        $this->artisan('accounting:refresh-invoice-statuses')->assertSuccessful();
        $this->assertSame('overdue', $invoice->fresh()->status);
        $before = $invoice->fresh()->getAttributes();
        $this->artisan('accounting:refresh-invoice-statuses')->assertSuccessful();
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame('100.00', $invoice->fresh()->total_amount);
        $this->assertSame('40.00', $invoice->fresh()->amount_paid);
        $this->assertSame('900.00', $invoice->fresh()->previous_balance);
        $this->assertSame('partially_paid', Invoice::withTrashed()->findOrFail($deleted->id)->status);
    }
}
