<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Square;
use App\Models\Tariff;
use App\Services\DataExportService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

final class ExactFinancialExportTest extends TestCase
{
    use RefreshDatabase;

    private function readWorkbook(string $path): array
    {
        $reader = new Reader();
        try {
            $reader->open($path);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
                }
            }
            return $rows;
        } finally {
            $reader->close();
            @unlink($path);
        }
    }

    public function test_exports_preserve_numeric_cents_historical_balance_and_reading_quantity(): void
    {
        $square = Square::create(['name' => 'Synthetic export']);
        $subscriber = $square->subscribers()->create(['name' => 'Export debtor', 'status' => 'active']);
        $creditor = $square->subscribers()->create(['name' => 'Export creditor', 'status' => 'active']);
        $meter = Meter::create(['subscriber_id' => $subscriber->id, 'unique_code' => 'P3-XLSX']);
        Tariff::create(['name' => 'P3 export tariff', 'unit_price' => '0.30', 'is_active' => true, 'start_date' => '2026-01-01']);
        $service = app(InvoiceService::class);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'previous_reading' => 0, 'current_reading' => 1]);
        $first = $service->generateInvoice($subscriber, $meter);
        app(PaymentService::class)->collectPayment($subscriber, '0.10', $first->id);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-03-01',
            'period_start' => '2026-02-01', 'period_end' => '2026-03-01', 'previous_reading' => 1, 'current_reading' => '2.50']);
        $second = $service->generateInvoice($subscriber, $meter);
        app(PaymentService::class)->collectPayment($creditor, '0.10');
        app(PaymentService::class)->collectPayment($creditor, '0.20');
        $before = DB::table('transactions')->orderBy('id')->get()->toArray();

        $exports = app(DataExportService::class);
        $rows = $this->readWorkbook($exports->exportSubscribers($square->id));
        $this->assertCount(3, $rows);
        $byName = array_column(array_slice($rows, 1), null, 1);
        foreach ([8 => 0.0, 9 => 0.65, 10 => -0.65] as $column => $expected) {
            $value = $byName[$subscriber->name][$column];
            $this->assertTrue(is_int($value) || is_float($value));
            $this->assertSame($expected, (float) $value);
        }
        $this->assertSame(0.30, (float) $byName[$creditor->name][8]);
        $this->assertSame(0.30, (float) $byName[$creditor->name][10]);

        $rows = $this->readWorkbook($exports->exportInvoices($square->id));
        $this->assertCount(3, $rows);
        $byNumber = array_column(array_slice($rows, 1), null, 1);
        $new = $byNumber[$second->bill_number];
        foreach ([10 => 0.45, 11 => 0.20, 12 => 0.45, 13 => 0.65, 14 => 0.0, 15 => 0.45] as $column => $expected) {
            $this->assertTrue(is_int($new[$column]) || is_float($new[$column]));
            $this->assertSame($expected, (float) $new[$column]);
        }
        $this->assertSame(0.20, (float) $byNumber[$first->bill_number][15]);

        $rows = $this->readWorkbook($exports->exportReadings($square->id));
        $this->assertCount(3, $rows);
        $this->assertSame(1.0, (float) $rows[1][8]);
        $this->assertSame(2.50, (float) $rows[1][9]);
        $this->assertSame(1.50, (float) $rows[1][10]);
        $this->assertEquals($before, DB::table('transactions')->orderBy('id')->get()->toArray());
    }

    public function test_invoice_export_rejects_overallocation_without_rewriting_documents(): void
    {
        $subscriber = Square::create(['name' => 'Invalid export'])->subscribers()
            ->create(['name' => 'Invalid export account', 'status' => 'active']);
        (new Invoice())->forceFill(['subscriber_id' => $subscriber->id, 'bill_number' => 'P3-INVALID-XLSX',
            'bill_date' => today()->toDateString(), 'status' => 'issued', 'consumption_fee' => '1.00',
            'previous_balance' => '0.00', 'total_amount' => '1.00', 'amount_paid' => '1.01'])->saveQuietly();
        $before = DB::table('invoices')->get()->toArray();
        try {
            $path = app(DataExportService::class)->exportInvoices();
            @unlink($path);
            $this->fail('Impossible allocation was exported as valid.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('allocation', $error->getMessage());
        }
        $this->assertEquals($before, DB::table('invoices')->get()->toArray());
    }
}
