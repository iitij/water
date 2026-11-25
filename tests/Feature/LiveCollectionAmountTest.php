<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Square;
use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveCollectionAmountTest extends TestCase
{
    use RefreshDatabase;

    private function meter(): Meter
    {
        $subscriber = Subscriber::create([
            'name' => 'Accounting regression',
            'square_id' => Square::create(['name' => 'Accounting test'])->id,
            'status' => 'active',
        ]);
        return Meter::create(['unique_code' => 'ACC-01-'.$subscriber->id, 'subscriber_id' => $subscriber->id]);
    }

    private function invoice(Meter $meter, float $total, float $paid, float $previous = 0, string $status = 'issued', bool $general = false): Invoice
    {
        // Set historical allocations explicitly; do not let the creation observer
        // rewrite the fixture. Tests do not import or alter real customer data.
        return Invoice::withoutEvents(fn () => Invoice::create([
            'subscriber_id' => $meter->subscriber_id,
            'meter_id' => $general ? null : $meter->id,
            'invoice_type' => 'meter',
            'bill_number' => 'SYNTH-LIVE-'.\Illuminate\Support\Str::uuid(),
            'bill_date' => now()->toDateString(),
            'consumption_fee' => $total,
            'total_amount' => $total,
            'amount_paid' => $paid,
            'previous_balance' => $previous,
            'status' => $status,
        ]))->fresh(); // Assert against actual persisted DECIMAL values, not the input float.
    }

    public function test_paid_historical_arrears_are_never_suggested_again(): void
    {
        $meter = $this->meter();
        $old = $this->invoice($meter, 101400, 101400, 0, 'paid');
        $latest = $this->invoice($meter, 600, 0, 101400, 'partially_paid');

        $this->assertSame(0.0, $old->getCollectibleAmount());
        $this->assertSame(600.0, $latest->getCollectibleAmount());
        $this->assertSame('101400.00', $latest->fresh()->previous_balance);
    }

    public function test_latest_includes_only_live_older_debt_and_older_is_independent(): void
    {
        $meter = $this->meter();
        $old = $this->invoice($meter, 26400, 21400, 0, 'partially_paid');
        $latest = $this->invoice($meter, 0, 0, 26400, 'partially_paid');

        $this->assertSame(5000.0, $latest->getCollectibleAmount());
        $this->assertSame(5000.0, $old->getCollectibleAmount());

        $old->updateQuietly(['amount_paid' => 26400, 'status' => 'paid']);
        $this->assertSame(0.0, $latest->fresh()->getCollectibleAmount());
    }

    public function test_includes_general_items_but_excludes_drafts_deleted_and_other_subscribers(): void
    {
        $meter = $this->meter();
        $other = $this->meter();
        $this->invoice($other, 90000, 0);
        $general = $this->invoice($meter, 8000, 0, 0, 'issued', true);
        $this->invoice($meter, 9000, 0, 0, 'draft');
        $deleted = $this->invoice($meter, 4000, 0);
        $deleted->deleteQuietly();
        $latest = $this->invoice($meter, 600, 100);
        $draft = $this->invoice($meter, 7000, 0, 0, 'draft');

        $this->assertSame(8500.0, $latest->fresh()->getCollectibleAmount());
        $this->assertSame(8000.0, $general->fresh()->getCollectibleAmount());
        $this->assertSame(0.0, $draft->getCollectibleAmount());
        $this->assertSame(0.0, $deleted->getCollectibleAmount());
    }
}
