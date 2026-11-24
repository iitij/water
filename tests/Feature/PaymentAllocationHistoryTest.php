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

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Tariff::create(['name' => 'Synthetic baseline tariff', 'unit_price' => 600, 'is_active' => true]);
    }

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

    public function test_read_projection_distinguishes_uninitialized_account_from_zero_and_does_not_write(): void
    {
        $subscriber = $this->account();
        $reader = app(\App\Services\PaymentAllocationReadService::class);
        $initial = $reader->reconcile($subscriber);
        $this->assertFalse($initial['available']);
        $this->assertNull($initial['matches']);
        $this->assertDatabaseCount('payment_allocation_revisions', 0);

        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'ACC07-3']);
        $invoice = $this->bill($meter, '2026-01-01', '2026-01-31', 0, 1);
        $receipt = app(PaymentService::class)->collectPayment($subscriber, 750.25, $invoice->id);
        $before = $this->current($subscriber);
        $this->assertTrue($reader->reconcile($subscriber)['matches']);
        $this->assertSame(60000, $reader->forInvoice($invoice)['allocated_minor']);
        $this->assertSame(15025, $reader->forPayment($receipt)['unallocated_minor']);
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/payments/'.$receipt->id)->assertOk()
            ->assertJsonPath('payment.allocation_snapshot.allocated_minor', 60000);
        $this->getJson('/api/invoices/'.$invoice->id)->assertOk()
            ->assertJsonPath('invoice.allocation_snapshot.allocated_minor', 60000);
        $this->getJson('/api/subscribers/'.$subscriber->id.'/allocation-reconciliation')->assertOk()
            ->assertJsonPath('matches', true)->assertJsonPath('difference_minor', 0);
        $this->assertSame($before->id, $this->current($subscriber)->id);

        // Deliberately simulate a legacy accounting discrepancy; the read audit
        // must report it without silently changing the account or replaying money.
        DB::table('transactions')->where('subscriber_id', $subscriber->id)->where('type', 'payment')->update(['amount' => 750]);
        $this->assertFalse($reader->reconcile($subscriber)['matches']);
        $this->assertSame(-25, $reader->reconcile($subscriber)['difference_minor']);
        $this->assertSame($before->id, $this->current($subscriber)->id);
    }


    public function test_database_rejects_rewriting_or_deleting_saved_allocations(): void
    {
        $subscriber = $this->account();
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'ACC07-4']);
        $invoice = $this->bill($meter, '2026-01-01', '2026-01-31', 0, 1);
        app(PaymentService::class)->collectPayment($subscriber, 100, $invoice->id);
        $revision = $this->current($subscriber);
        $originalRows = $this->rows($revision);
        $protection = app(\App\Services\AllocationHistoryProtection::class);
        if (DB::getDriverName() !== 'sqlite' && ! $protection->active()) {
            // Restricted hosting: a raw write is detectable, not preventable.
            DB::table('payment_allocations')->where('revision_id', $revision->id)->update(['amount_minor' => 1]);
            $reader = app(\App\Services\PaymentAllocationReadService::class);
            $this->assertFalse($reader->reconcile($subscriber)['integrity_valid']);
            $this->assertFalse($reader->reconcile($subscriber)['matches']);
            $this->assertFalse($reader->forInvoice($invoice)['available']);
            try {
                app(PaymentService::class)->collectPayment($subscriber, 20, $invoice->id);
                $this->fail('Replay accepted a damaged allocation history.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('integrity failure', $e->getMessage());
            }
            $this->assertDatabaseCount('payments', 1);
            $this->assertSame($revision->id, $this->current($subscriber)->id);
            return;
        }
        $this->assertTrue($protection->active());
        foreach ([
            fn () => DB::table('payment_allocation_revisions')->where('id', $revision->id)->update(['available_minor' => 1]),
            fn () => DB::table('payment_allocation_revisions')->where('id', $revision->id)->delete(),
            fn () => DB::table('payment_allocations')->where('revision_id', $revision->id)->update(['amount_minor' => 1]),
            fn () => DB::table('payment_allocations')->where('revision_id', $revision->id)->delete(),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Saved accounting history was mutable.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('Allocation history is append-only', $e->getMessage());
            }
        }
        $this->assertSame($originalRows, $this->rows($revision));
        $this->assertSame(10000, (int) $this->current($subscriber)->available_minor);
    }

}
