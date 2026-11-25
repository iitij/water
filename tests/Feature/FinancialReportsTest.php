<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReportsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
    }

    public function test_report_returns_financial_totals_and_all_chart_contracts_without_changing_records(): void
    {
        [$first, $second] = $this->seedReport();
        $before = Invoice::withTrashed()->get()->toArray();

        $response = $this->getJson('/api/admin/reports?from_date=2026-10-01&to_date=2026-10-31')
            ->assertOk()
            ->assertJsonPath('filters', ['from_date' => '2026-10-01', 'to_date' => '2026-10-31', 'square_id' => null])
            ->assertJsonPath('summary.collection_total', 205)
            ->assertJsonPath('summary.total_collected', 205)
            ->assertJsonPath('summary.invoices_total', 640)
            ->assertJsonPath('summary.total_invoiced', 640)
            ->assertJsonPath('summary.total_outstanding', 430)
            ->assertJsonPath('summary.overdue_amount', 280)
            ->assertJsonPath('summary.invoices_count', 4)
            ->assertJsonPath('summary.paid_invoices_count', 2)
            ->assertJsonPath('summary.overdue_invoices_count', 1)
            ->assertJsonPath('summary.readings_count', 0)
            ->assertJsonPath('summary.consumption_units', 0)
            ->assertJsonPath('revenue_series', [
                ['label' => '2026-10-01', 'value' => 50],
                ['label' => '2026-10-15', 'value' => 30],
                ['label' => '2026-10-31', 'value' => 125],
            ])
            ->assertJsonCount(2, 'square_breakdown');

        $statuses = collect($response->json('status_breakdown'))->keyBy('key');
        $this->assertEquals(['key' => 'paid', 'label' => 'مسددة', 'value' => 140, 'count' => 2], $statuses['paid']);
        $this->assertEquals(['key' => 'partially_paid', 'label' => 'مسددة جزئياً', 'value' => 200, 'count' => 1], $statuses['partially_paid']);
        $this->assertEquals(['key' => 'overdue', 'label' => 'متأخرة', 'value' => 300, 'count' => 1], $statuses['overdue']);
        $squares = collect($response->json('square_breakdown'))->keyBy('key');
        $this->assertEquals(['key' => (string) $first->square_id, 'label' => 'المربع الأول', 'value' => 175, 'count' => 2], $squares[$first->square_id]);
        $this->assertEquals(['key' => (string) $second->square_id, 'label' => 'المربع الثاني', 'value' => 30, 'count' => 1], $squares[$second->square_id]);
        $this->assertEquals(205, collect($response->json('collections_by_method'))->sum('total'));
        $this->assertEquals(4, collect($response->json('invoices_by_status'))->sum('total'));
        $this->assertSame($before, Invoice::withTrashed()->get()->toArray());
    }

    public function test_square_and_date_filters_apply_to_summary_and_every_breakdown(): void
    {
        [$first] = $this->seedReport();
        $this->getJson('/api/admin/reports?from_date=2026-10-31&to_date=2026-10-31&square_id='.$first->square_id)
            ->assertOk()
            ->assertJsonPath('filters.square_id', (string) $first->square_id)
            ->assertJsonPath('summary.total_collected', 125)
            ->assertJsonPath('summary.total_invoiced', 200)
            ->assertJsonPath('summary.total_outstanding', 150)
            ->assertJsonPath('summary.overdue_amount', 0)
            ->assertJsonPath('summary.invoices_count', 1)
            ->assertJsonPath('summary.paid_invoices_count', 0)
            ->assertJsonPath('summary.overdue_invoices_count', 0)
            ->assertJsonPath('revenue_series', [['label' => '2026-10-31', 'value' => 125]])
            ->assertJsonPath('status_breakdown', [['key' => 'partially_paid', 'label' => 'مسددة جزئياً', 'value' => 200, 'count' => 1]])
            ->assertJsonPath('square_breakdown', [['key' => (string) $first->square_id, 'label' => 'المربع الأول', 'value' => 125, 'count' => 1]])
            ->assertJsonPath('invoices_by_status', [['status' => 'partially_paid', 'total' => 1]])
            ->assertJsonCount(1, 'collections_by_method');
    }

    public function test_default_dates_use_current_server_month_and_return_effective_filters(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0));
        $this->seedReport();

        $this->getJson('/api/admin/reports')->assertOk()
            ->assertJsonPath('filters', ['from_date' => '2026-10-01', 'to_date' => '2026-10-31', 'square_id' => null])
            ->assertJsonPath('summary.total_collected', 205)
            ->assertJsonPath('summary.total_invoiced', 640);
    }

    public function test_empty_period_has_zero_totals_and_empty_breakdowns(): void
    {
        $this->seedReport();
        $this->getJson('/api/admin/reports?from_date=2026-12-01&to_date=2026-12-31')
            ->assertOk()->assertJsonPath('summary', [
                'collection_total' => 0, 'invoices_total' => 0,
                'total_collected' => 0, 'total_invoiced' => 0,
                'total_outstanding' => 0, 'overdue_amount' => 0,
                'paid_invoices_count' => 0, 'overdue_invoices_count' => 0,
                'invoices_count' => 0, 'readings_count' => 0, 'consumption_units' => 0,
            ])
            ->assertJsonPath('revenue_series', [])->assertJsonPath('status_breakdown', [])
            ->assertJsonPath('square_breakdown', [])->assertJsonPath('invoices_by_status', [])
            ->assertJsonPath('collections_by_method', []);
    }

    public function test_month_end_dashboard_and_report_share_valid_receipts_without_future_dates(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0));
        [$first] = $this->seedReport();
        $finance = app(\App\Services\FinancialReportingService::class);
        $months = $finance->monthlyCollections();
        $this->assertSame(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], array_column($months, 'month'));
        $this->assertSame(205.0, $months[5]['value']);
        $this->assertSame(175.0, $finance->grossReceipts('2026-10-01', '2026-10-31', $first->square_id));
        $this->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('stats_overview.monthly_collection', 205)
            ->assertJsonPath('monthly_revenue_chart.data.5', 205);
        $this->getJson('/api/admin/reports')->assertOk()->assertJsonPath('summary.total_collected', 205);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        $this->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('stats_overview.monthly_collection', 80)
            ->assertJsonPath('monthly_revenue_chart.data.5', 80);
    }

    public function test_current_account_debt_and_credit_are_not_netted_or_filtered_by_invoice_dates(): void
    {
        $square = Square::create(['name' => 'Ledger report test']);
        $debtor = $square->subscribers()->create(['name' => 'Debtor', 'status' => 'active']);
        $creditor = $square->subscribers()->create(['name' => 'Creditor', 'status' => 'active']);
        $other = Square::create(['name' => 'Other'])->subscribers()->create(['name' => 'Other creditor', 'status' => 'active']);
        $meter = \App\Models\Meter::create(['subscriber_id' => $debtor->id, 'unique_code' => 'REPORT-LEDGER']);
        \App\Models\Tariff::create(['name' => 'Synthetic report tariff', 'unit_price' => 600, 'is_active' => true]);
        $this->createMeasuredReading(['meter_id' => $meter->id, 'reading_date' => '2026-02-01',
            'period_start' => '2026-01-01', 'period_end' => '2026-02-01', 'previous_reading' => 0, 'current_reading' => 1]);
        app(\App\Services\InvoiceService::class)->generateInvoice($debtor, $meter);
        app(\App\Services\PaymentService::class)->collectPayment($creditor, 250.25);
        app(\App\Services\PaymentService::class)->collectPayment($other, 100);
        $this->getJson('/api/admin/reports?from_date=2025-01-01&to_date=2025-01-31&square_id='.$square->id)
            ->assertOk()->assertJsonPath('summary.total_outstanding', 0)
            ->assertJsonPath('account_balances.debt', 600)
            ->assertJsonPath('account_balances.credit', 250.25)
            ->assertJsonPath('account_balances.basis', 'current_subscriber_ledger');
        $this->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('stats_overview.outstanding_amount', 600)
            ->assertJsonPath('stats_overview.account_credit', 350.25);
    }

    public function test_invalid_filters_are_rejected_and_existing_authorization_is_retained(): void
    {
        $this->getJson('/api/admin/reports?from_date=2026-10-31&to_date=2026-10-01')
            ->assertUnprocessable()->assertJsonValidationErrors('to_date');
        $this->getJson('/api/admin/reports?square_id=999')->assertUnprocessable()->assertJsonValidationErrors('square_id');
        $this->actingAs(User::factory()->create(['role' => 'reader']), 'sanctum');
        $this->getJson('/api/admin/reports')->assertForbidden();
    }

    public function test_dashboard_rejects_invalid_stored_invoice_allocation_without_mutating_it(): void
    {
        $subscriber = Square::create(['name' => 'Synthetic invalid allocation'])->subscribers()
            ->create(['name' => 'Invalid allocation fixture', 'status' => 'active']);
        $this->invoice($subscriber, ['total_amount' => 40, 'amount_paid' => 60]);
        $before = Invoice::query()->get()->toArray();
        $this->getJson('/api/admin/dashboard')->assertServerError();
        $this->assertSame($before, Invoice::query()->get()->toArray());
    }

    private function seedReport(): array
    {
        $first = Square::create(['name' => 'المربع الأول'])->subscribers()->create(['name' => 'المشترك الأول', 'status' => 'active']);
        $second = Square::create(['name' => 'المربع الثاني'])->subscribers()->create(['name' => 'المشترك الثاني', 'status' => 'active']);
        $this->invoice($first, ['total_amount' => 100, 'amount_paid' => 100, 'status' => 'paid']);
        $this->invoice($first, ['bill_date' => '2026-10-31', 'total_amount' => 200, 'amount_paid' => 50, 'previous_balance' => 900, 'status' => 'partially_paid']);
        $this->invoice($second, ['total_amount' => 300, 'amount_paid' => 20, 'previous_balance' => 600, 'status' => 'overdue']);
        // Allocations cannot exceed the invoice's own billed amount. Excess funds belong to account credit.
        $this->invoice($second, ['total_amount' => 40, 'amount_paid' => 40, 'status' => 'paid']);
        $this->invoice($first, ['bill_date' => '2026-09-30', 'total_amount' => 80]);
        $this->invoice($first, ['total_amount' => 900, 'deleted_at' => now()]);
        $this->payment($first, 50, '2026-10-01 00:00:00');
        $this->payment($first, 125, '2026-10-31 23:59:59', ['payment_method' => 'bank_transfer']);
        $this->payment($second, 30, '2026-10-15 08:30:00');
        $this->payment($first, 900, '2026-10-31 12:00:00', ['voided_at' => now()]);
        $this->payment($first, 800, '2026-10-31 12:00:00', ['deleted_at' => now()]);
        $this->payment($first, 700, '2026-09-30 23:59:59');
        $this->payment($first, 600, '2026-11-01 00:00:00');

        return [$first, $second];
    }

    private function invoice(Subscriber $subscriber, array $attributes): void
    {
        // Read-model fixtures preserve recorded allocation/status without invoking
        // PaymentService, which is responsible for recalculation on actual writes.
        (new Invoice)->forceFill(array_merge([
            'subscriber_id' => $subscriber->id, 'bill_number' => 'REPORT-'.++$this->sequence,
            'bill_date' => '2026-10-01', 'total_amount' => 0, 'amount_paid' => 0, 'status' => 'issued',
        ], $attributes))->saveQuietly();
    }

    private function payment(Subscriber $subscriber, float $amount, string $date, array $attributes = []): void
    {
        (new Payment)->forceFill(array_merge([
            'subscriber_id' => $subscriber->id, 'receipt_number' => 'REPORT-'.++$this->sequence,
            'amount' => $amount, 'payment_date' => $date, 'payment_method' => 'cash',
        ], $attributes))->saveQuietly();
    }
}
