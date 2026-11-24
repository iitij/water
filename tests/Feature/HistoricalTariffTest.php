<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HistoricalTariffTest extends TestCase
{
    use RefreshDatabase;

    private function meter(): Meter
    {
        $subscriber = Subscriber::create(['name' => 'Synthetic historical tariff',
            'square_id' => Square::create(['name' => 'Tariff test'])->id,
            'status' => 'active']);
        return Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'TARIFF-1']);
    }

    private function tariff(string $price, ?string $start, ?string $end): Tariff
    {
        return Tariff::create(['name' => 'Historical tariff '.$price, 'unit_price' => $price,
            'is_active' => true, 'start_date' => $start, 'end_date' => $end]);
    }

    private function read(Meter $meter, string $start, string $end, int $previous, int $current): Reading
    {
        return Reading::create(['meter_id' => $meter->id, 'reading_date' => '2026-04-10',
            'period_start' => $start, 'period_end' => $end,
            'previous_reading' => $previous, 'current_reading' => $current]);
    }

    public function test_each_measured_period_uses_its_own_tariff_not_invoice_date(): void
    {
        $meter = $this->meter();
        $old = $this->tariff('600.25', '2026-01-01', '2026-01-31');
        $new = $this->tariff('800.50', '2026-02-01', '2026-02-28');
        $this->tariff('1000.00', '2026-03-01', null);
        $first = $this->read($meter, '2026-01-01', '2026-02-01', 0, 10);
        $second = $this->read($meter, '2026-02-01', '2026-03-01', 10, 15);
        $invoice = app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter);
        $this->assertSame('10005.00', $invoice->total_amount);
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $invoice->id,
            'reading_id' => $first->id, 'tariff_id' => $old->id, 'unit_price' => 600.25, 'amount' => 6002.50]);
        $this->assertDatabaseHas('invoice_tariff_lines', ['invoice_id' => $invoice->id,
            'reading_id' => $second->id, 'tariff_id' => $new->id, 'unit_price' => 800.50, 'amount' => 4002.50]);
        $this->assertSame(10005.0, $meter->subscriber->getAccountDebt());
        $this->assertDatabaseCount('invoice_tariff_lines', 2);
    }

    public function test_missing_tariff_does_not_fall_back_to_an_arbitrary_price(): void
    {
        $meter = $this->meter();
        $reading = $this->read($meter, '2026-01-01', '2026-02-01', 0, 10);
        $this->assertRejected($meter, $reading);
    }

    public function test_crossing_a_price_change_without_boundary_reading_is_rejected(): void
    {
        $meter = $this->meter();
        $this->tariff('600.00', '2026-01-01', '2026-01-31');
        $this->tariff('800.00', '2026-02-01', null);
        $reading = $this->read($meter, '2026-01-01', '2026-03-01', 0, 15);
        $this->assertRejected($meter, $reading);
    }

    public function test_partial_coverage_and_overlapping_prices_are_rejected(): void
    {
        $meter = $this->meter();
        $this->tariff('600.00', '2026-01-15', '2026-01-31');
        $reading = $this->read($meter, '2026-01-01', '2026-02-01', 0, 10);
        $this->assertRejected($meter, $reading);
        $this->tariff('700.00', '2026-01-01', null);
        $this->assertRejected($meter, $reading);
    }

    private function assertRejected(Meter $meter, Reading $reading): void
    {
        try {
            app(InvoiceService::class)->generateInvoice($meter->subscriber, $meter);
            $this->fail('Invalid tariff period was billed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tariff', $exception->errors());
        }
        $this->assertFalse($reading->fresh()->is_invoiced);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('invoice_tariff_lines', 0);
    }
}
