<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Square;
use App\Models\Subscriber;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedSubscriberAccountTest extends TestCase
{
    use RefreshDatabase;

    private function meter(): Meter
    {
        $subscriber = Subscriber::create([
            'name' => 'Synthetic account',
            'square_id' => Square::create(['name' => 'Accounting square'])->id,
            'status' => 'active',
            'subscription_start_date' => '2026-01-01',
        ]);
        return Meter::create([
            'unique_code' => 'ACC-02-'.$subscriber->id,
            'subscriber_id' => $subscriber->id,
            'initial_reading' => 0,
        ]);
    }

    private function bill(Meter $meter, string $start, string $end, int $previous, int $current): Invoice
    {
        Reading::create([
            'meter_id' => $meter->id,
            'reading_date' => $end,
            'actual_reading_date' => $end,
            'period_start' => $start,
            'period_end' => $end,
            'previous_reading' => $previous,
            'current_reading' => $current,
            'is_invoiced' => false,
        ]);
        return app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter)->fresh();
    }

    public function test_general_advance_pays_future_water_invoices_without_double_deduction(): void
    {
        $meter = $this->meter();
        $subscriber = $meter->subscriber;
        $payments = app(PaymentService::class);
        $advance = $payments->collectPayment($subscriber, 12000);
        $this->assertNull($advance->meter_id);
        $this->assertSame(12000.0, $subscriber->getAccountCredit());

        $first = $this->bill($meter, '2026-01-01', '2026-01-31', 0, 10);
        $this->assertSame('6000.00', $first->total_amount);
        $this->assertSame('6000.00', $first->credit_applied);
        $this->assertSame('6000.00', $first->amount_paid);
        $this->assertSame('paid', $first->status);
        $this->assertSame(6000.0, $subscriber->getAccountBalance());

        $second = $this->bill($meter, '2026-02-01', '2026-02-28', 10, 15);
        $this->assertSame('3000.00', $second->total_amount);
        $this->assertSame('3000.00', $second->amount_paid);
        $this->assertSame(3000.0, $subscriber->getAccountBalance());
        $this->assertSame(0.0, $subscriber->getAccountDebt());
        $this->assertSame(0.0, $second->getCollectibleAmount());

        $payments->voidPayment($advance, 'Synthetic cancellation of advance');
        $this->assertSame('0.00', $first->fresh()->amount_paid);
        $this->assertSame('0.00', $second->fresh()->amount_paid);
        $this->assertSame(9000.0, $subscriber->getAccountDebt());
        $this->assertSame(0.0, $subscriber->getAccountCredit());
    }

    public function test_general_payment_allocates_to_water_debt_and_never_another_subscriber(): void
    {
        $meter = $this->meter();
        $other = $this->meter();
        $first = $this->bill($meter, '2026-01-01', '2026-01-31', 0, 1);
        $second = $this->bill($meter, '2026-02-01', '2026-02-28', 1, 3);
        $unrelated = $this->bill($other, '2026-01-01', '2026-01-31', 0, 2);

        app(PaymentService::class)->collectPayment($meter->subscriber, 1000.25);
        $this->assertSame('600.00', $first->fresh()->amount_paid);
        $this->assertSame('400.25', $second->fresh()->amount_paid);
        $this->assertSame('0.00', $unrelated->fresh()->amount_paid);
        $this->assertSame(799.75, $meter->subscriber->getAccountDebt());
        $this->assertSame(1200.0, $other->subscriber->getAccountDebt());

        app(PaymentService::class)->recalculateInvoices($meter->subscriber, $meter->id);
        $this->assertSame('400.25', $second->fresh()->amount_paid);
        $this->assertSame(799.75, $second->fresh()->getCollectibleAmount());
    }

    public function test_draft_is_not_funded_or_activated_by_a_payment(): void
    {
        $meter = $this->meter();
        $draft = Invoice::withoutEvents(fn () => Invoice::create([
            'subscriber_id' => $meter->subscriber_id,
            'meter_id' => $meter->id,
            'invoice_type' => 'meter',
            'bill_number' => 'DRAFT-ACC02',
            'bill_date' => '2026-01-01',
            'status' => 'draft',
            'total_amount' => 999,
        ]));
        app(PaymentService::class)->collectPayment($meter->subscriber, 100);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('0.00', $draft->fresh()->amount_paid);
        $this->assertSame(100.0, $meter->subscriber->getAccountCredit());
    }

    public function test_latest_invoice_status_is_independent_of_older_arrears(): void
    {
        $meter = $this->meter();
        $first = $this->bill($meter, '2026-01-01', '2026-01-31', 0, 10);
        app(PaymentService::class)->collectPayment($meter->subscriber, 2000, $first->id);
        $second = $this->bill($meter, '2026-02-01', '2026-02-28', 10, 15);

        $this->assertSame('partially_paid', $first->fresh()->status);
        $this->assertSame('issued', $second->fresh()->status);
        $this->assertSame(7000.0, $second->fresh()->getCollectibleAmount());

        $payment = app(PaymentService::class)->collectPayment($meter->subscriber, 3000, $second->id);
        $this->assertSame('paid', $second->fresh()->status);
        $this->assertSame('3000.00', $second->fresh()->amount_paid);
        $this->assertSame('partially_paid', $first->fresh()->status);
        $this->assertSame(0.0, $second->fresh()->getRemainingAmount());
        $this->assertSame(4000.0, $second->fresh()->getCollectibleAmount());
        $this->assertSame(4000.0, $meter->subscriber->getAccountDebt());
        $this->assertTrue($second->fresh()->canCollect());
        $this->assertTrue($first->fresh()->canCollect());
        $this->assertSame([$first->id], Invoice::where('subscriber_id', $meter->subscriber_id)->withOwnDebt()->pluck('id')->all());
        $this->assertSame([$first->id, $second->id], Invoice::collectibleForSubscriber($meter->subscriber_id)->orderBy('id')->pluck('id')->all());

        app(PaymentService::class)->voidPayment($payment, 'Synthetic targeted receipt correction');
        $this->assertSame('issued', $second->fresh()->status);
        $this->assertSame(7000.0, $meter->subscriber->getAccountDebt());
    }

    public function test_zero_consumption_invoice_is_paid_even_with_older_debt(): void
    {
        $meter = $this->meter();
        $first = $this->bill($meter, '2026-01-01', '2026-01-31', 0, 10);
        $second = $this->bill($meter, '2026-02-01', '2026-02-28', 10, 10);
        $this->assertSame('0.00', $second->total_amount);
        $this->assertSame('paid', $second->status);
        $this->assertSame(0.0, $second->getRemainingAmount());
        $this->assertSame(6000.0, $second->getCollectibleAmount());
        $this->assertSame(6000.0, $meter->subscriber->getAccountDebt());
        $this->assertNotSame('paid', $first->fresh()->status);
    }

}
