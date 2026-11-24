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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentAllocationHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function account(): Subscriber
    {
        return Subscriber::create([
            'name' => 'Synthetic allocation account',
            'square_id' => Square::create(['name' => 'Allocation test'])->id,
            'status' => 'active',
        ]);
    }

    private function bill(Meter $meter, string $start, string $end, int $previous, int $current): Invoice
    {
        Reading::create(['meter_id' => $meter->id, 'reading_date' => $end,
            'period_start' => $start, 'period_end' => $end,
            'previous_reading' => $previous, 'current_reading' => $current]);
        return app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter);
    }

    private function current(Subscriber $subscriber): object
    {
        return DB::table('payment_allocation_revisions')->where('subscriber_id', $subscriber->id)
            ->orderByDesc('revision')->first();
    }

    private function rows(object $revision): array
    {
        return DB::table('payment_allocations')->where('revision_id', $revision->id)
            ->orderBy('position')->get()->map(fn ($row) => [
                $row->payment_id === null ? null : (int) $row->payment_id,
                $row->invoice_id === null ? null : (int) $row->invoice_id,
                (int) $row->amount_minor,
            ])->all();
    }

    public function test_target_fifo_credit_and_void_create_conserved_immutable_revisions(): void
    {
        $subscriber = $this->account();
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'ACC07-1']);
        $first = $this->bill($meter, '2026-01-01', '2026-01-31', 0, 1);
        $second = $this->bill($meter, '2026-02-01', '2026-02-28', 1, 3);
        $service = app(PaymentService::class);
        $payment = $service->collectPayment($subscriber, 2000.25, $second->id);
        $revision = $this->current($subscriber);
        $expected = [
            [$payment->id, $second->id, 120000],
            [$payment->id, $first->id, 60000],
            [$payment->id, null, 20025],
        ];
        $this->assertSame($expected, $this->rows($revision));
        $this->assertSame(200025, (int) $revision->available_minor);
        $this->assertSame(180000, (int) $revision->allocated_minor);
        $this->assertSame(20025, (int) $revision->unallocated_minor);
        $service->recalculateInvoices($subscriber);
        $this->assertSame($revision->id, $this->current($subscriber)->id);

        $third = $this->bill($meter, '2026-03-01', '2026-03-31', 3, 4);
        $next = $this->current($subscriber);
        $this->assertNotSame($revision->id, $next->id);
        $this->assertSame([$payment->id, $third->id, 20025], $this->rows($next)[2]);
        $this->assertSame('200.25', $third->fresh()->amount_paid);
        $this->assertSame(0, (int) $next->unallocated_minor);
        $this->assertSame($expected, $this->rows($revision));

        $service->voidPayment($payment, 'Synthetic allocation reversal test');
        $after = $this->current($subscriber);
        $this->assertSame([], $this->rows($after));
        $this->assertSame(0, (int) $after->available_minor);
        $this->assertSame($expected, $this->rows($revision));
        $this->assertSame('0.00', $first->fresh()->amount_paid);
        $this->assertSame('0.00', $third->fresh()->amount_paid);
        $this->assertSame(2400.0, $subscriber->getAccountDebt());
    }

    public function test_failure_to_save_allocation_rolls_back_receipt_and_ledger(): void
    {
        $subscriber = $this->account();
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'ACC07-2']);
        $invoice = $this->bill($meter, '2026-01-01', '2026-01-31', 0, 1);
        $before = $this->current($subscriber);
        $this->mock(\App\Services\PaymentAllocationHistory::class, function ($mock) {
            $mock->shouldReceive('append')->once()->andThrow(new \RuntimeException('Synthetic allocation write failure'));
        });
        try {
            app(PaymentService::class)->collectPayment($subscriber, 100, $invoice->id);
            $this->fail('Receipt should not survive allocation write failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic allocation write failure', $e->getMessage());
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame('0.00', $invoice->fresh()->amount_paid);
        $this->assertSame($before->id, $this->current($subscriber)->id);
    }
}
