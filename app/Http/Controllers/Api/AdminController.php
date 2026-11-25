<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Subscriber;
use App\Services\FinancialReportingService;
use App\Support\MoneyBoundary;
use App\Support\ReadingQuantity;
use App\Support\SignedMinor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AdminController extends ApiController
{
    public function stats()
    {
        $this->authorizeApi(Invoice::class, 'viewAny');
        $overview = $this->overview();

        return response()->json([
            'subscribers_count' => $overview['total_subscribers_count'],
            'readings_this_month' => $overview['readings_this_month'],
            'collected_this_month' => $overview['monthly_collection'],
            'unpaid_invoices_count' => $overview['unpaid_invoices_count'],
            'total_unpaid_amount' => $overview['outstanding_amount'],
        ]);
    }

    public function dashboard()
    {
        $this->authorizeApi(Invoice::class, 'viewAny');
        $revenue = $this->monthlyRevenueData();
        $latest = $this->latestInvoiceData();
        $overdue = $this->overdueInvoiceData();

        return response()->json([
            'stats_overview' => $this->overview(),
            'monthly_revenue_chart' => $revenue,
            'latest_invoices' => $latest,
            'overdue_invoices' => $overdue,
        ]);
    }

    public function monthlyRevenue()
    {
        $this->authorizeApi(Payment::class, 'viewAny');

        return response()->json($this->monthlyRevenueData());
    }

    public function latestInvoices()
    {
        $this->authorizeApi(Invoice::class, 'viewAny');

        return response()->json(['data' => $this->latestInvoiceData()]);
    }

    public function overdueInvoices()
    {
        $this->authorizeApi(Invoice::class, 'viewAny');

        return response()->json(['data' => $this->overdueInvoiceData()]);
    }

    public function reports(Request $request)
    {
        $this->authorizeApi(Invoice::class, 'viewAny');
        $filters = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'square_id' => ['nullable', 'integer', 'exists:squares,id'],
        ]);

        $from = Carbon::parse($filters['from_date'] ?? now()->startOfMonth())->toDateString();
        $to = Carbon::parse($filters['to_date'] ?? now())->toDateString();
        $squareId = $filters['square_id'] ?? null;

        if ($from > $to) {
            throw ValidationException::withMessages([
                'to_date' => 'يجب أن يكون تاريخ النهاية مساوياً لتاريخ البداية أو بعده.',
            ]);
        }

        // payment_date عمود datetime (منذ 2026-10-23)، لذا يجب تحديد اليوم كاملاً
        // وإلا تُستبعد مدفوعات اليوم نفسه بعد منتصف الليل من التقرير.
        $finance = app(FinancialReportingService::class);
        $paymentQuery = $finance->receipts($from, $to, $squareId !== null ? (int) $squareId : null);
        $invoiceQuery = Invoice::query()->whereNotIn('status', ['draft', 'cancelled'])->whereDate('bill_date', '>=', $from)->whereDate('bill_date', '<=', $to);
        $readingQuery = Reading::query()->whereDate('reading_date', '>=', $from)->whereDate('reading_date', '<=', $to);
        if ($squareId) {
            $invoiceQuery->whereHas('subscriber', fn ($q) => $q->where('square_id', $squareId));
            $readingQuery->where(function ($query) use ($squareId) {
                $query->whereHas('meter.subscriber', fn ($q) => $q->where('square_id', $squareId))
                    ->orWhere(fn ($q) => $q->whereNotNull('retired_meter_id')
                        ->whereHas('invoice.subscriber', fn ($sub) => $sub->where('square_id', $squareId)));
            });
        }

        $receiptTotals = $finance->receiptBreakdowns($paymentQuery);
        $collectionTotal = MoneyBoundary::numericMajorOutput($receiptTotals['minor']);
        $invoiceTotals = $finance->invoiceBreakdowns($invoiceQuery);
        $invoicesTotal = MoneyBoundary::numericMajorOutput($invoiceTotals['billed']);
        $consumption = $readingCount = 0;
        foreach ((clone $readingQuery)->select('consumption_units')->orderBy('id')->cursor() as $reading) {
            $consumption = SignedMinor::add($consumption, ReadingQuantity::hundredths($reading->getRawOriginal('consumption_units')));
            $readingCount++;
        }
        $statusLabels = [
            'draft' => 'مسودة',
            'issued' => 'غير مسددة',
            'partially_paid' => 'مسددة جزئياً',
            'paid' => 'مسددة',
            'overdue' => 'متأخرة',
        ];
        $statusTotals = collect($invoiceTotals['statuses']);

        return response()->json([
            'filters' => ['from_date' => $from, 'to_date' => $to, 'square_id' => $squareId],
            'summary' => [
                'collection_total' => $collectionTotal,
                'invoices_total' => $invoicesTotal,
                'total_collected' => $collectionTotal,
                'total_invoiced' => $invoicesTotal,
                'total_outstanding' => MoneyBoundary::numericMajorOutput($invoiceTotals['outstanding']),
                'overdue_amount' => MoneyBoundary::numericMajorOutput($invoiceTotals['overdue']),
                'paid_invoices_count' => $invoiceTotals['paid_count'],
                'overdue_invoices_count' => $invoiceTotals['overdue_count'],
                'invoices_count' => $invoiceTotals['count'],
                'readings_count' => $readingCount,
                'consumption_units' => MoneyBoundary::numericMajorOutput($consumption),
            ],
            'account_balances' => $finance->accountBalances($squareId !== null ? (int) $squareId : null),
            'financial_movements' => $finance->movementsFromMinor($from, $to, $squareId !== null ? (int) $squareId : null, $receiptTotals['minor']),
            'collections_by_method' => $receiptTotals['methods'],
            'invoices_by_status' => $statusTotals->map(fn ($row) => ['status' => $row['status'], 'total' => $row['total']]),
            'revenue_series' => $receiptTotals['revenue'],
            'status_breakdown' => $statusTotals->map(fn ($row) => [
                'key' => $row['status'],
                'label' => $statusLabels[$row['status']] ?? $row['status'],
                'value' => MoneyBoundary::numericMajorOutput($row['minor']),
                'count' => $row['total'],
            ]),
            'square_breakdown' => $receiptTotals['squares'],
        ]);
    }

    private function overview(): array
    {
        $monthStart = now()->startOfMonth();
        $unpaid = Invoice::withOwnDebt();
        $finance = app(FinancialReportingService::class);
        $balances = $finance->accountBalances();
        $outstanding = $balances['debt'];
        $subscriberSparkline = [];
        $collectionSparkline = [];
        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo)->toDateString();
            $subscriberSparkline[] = Subscriber::whereDate('created_at', $date)->count();
            $collectionSparkline[] = $finance->grossReceipts($date, $date);
        }

        return [
            'active_subscribers_count' => Subscriber::where('status', 'active')->count(),
            'total_subscribers_count' => Subscriber::count(),
            'outstanding_amount' => $outstanding,
            'account_credit' => $balances['credit'],
            'monthly_collection' => $finance->grossReceipts($monthStart->toDateString(), now()->toDateString()),
            'overdue_invoices_count' => Invoice::where('status', 'overdue')->count(),
            'unpaid_invoices_count' => (clone $unpaid)->count(),
            'readings_this_month' => Reading::whereDate('reading_date', '>=', $monthStart->toDateString())->count(),
            'subscribers_sparkline' => $subscriberSparkline,
            'collection_sparkline' => $collectionSparkline,
        ];
    }

    private function monthlyRevenueData(): array
    {
        $months = app(FinancialReportingService::class)->monthlyCollections();
        return ['labels' => array_column($months, 'label'), 'data' => array_column($months, 'value')];
    }

    private function latestInvoiceData()
    {
        return Invoice::with(['subscriber:id,name,phone,square_id', 'subscriber.square:id,name,code', 'meter:id,unique_code,path_number'])
            ->whereNotIn('status', ['draft', 'cancelled'])->latest('bill_date')->latest('id')->limit(5)->get()
            ->map(fn (Invoice $invoice) => $this->decorateInvoice($invoice));
    }

    private function overdueInvoiceData()
    {
        return Invoice::with(['subscriber:id,name,phone,square_id', 'meter:id,unique_code,path_number'])
            ->where('status', 'overdue')->orderBy('bill_date')->orderBy('id')
            ->limit(20)->get()->map(fn (Invoice $invoice) => $this->decorateInvoice($invoice));
    }

    private function decorateInvoice(Invoice $invoice): array
    {
        $data = $invoice->toArray();
        $data['true_remaining_amount'] = $invoice->getTrueRemainingAmount();
        $data['is_latest_invoice'] = $invoice->isLatestInvoice();

        return $data;
    }
}
