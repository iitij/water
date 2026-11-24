<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Subscriber;
use App\Services\FinancialReportingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        $collectionTotal = round((float) (clone $paymentQuery)->sum('amount'), 2);
        // total_amount is the invoice's own charge; previous_balance is carried
        // forward for display and must never be counted again in outstanding.
        $remaining = 'CASE WHEN total_amount > amount_paid THEN total_amount - amount_paid ELSE 0 END';
        $invoiceTotals = (clone $invoiceQuery)->selectRaw("COUNT(*) AS invoices_count,
            SUM(total_amount) AS invoices_total,
            SUM($remaining) AS outstanding_total,
            SUM(CASE WHEN status = 'overdue' THEN $remaining ELSE 0 END) AS overdue_total,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdue_count")->first();
        $invoicesTotal = round((float) $invoiceTotals->invoices_total, 2);
        $statusLabels = [
            'draft' => 'مسودة',
            'issued' => 'غير مسددة',
            'partially_paid' => 'مسددة جزئياً',
            'paid' => 'مسددة',
            'overdue' => 'متأخرة',
        ];
        $statusTotals = (clone $invoiceQuery)->select('status')
            ->selectRaw('SUM(total_amount) AS amount, COUNT(*) AS total')
            ->groupBy('status')->orderBy('status')->get();
        $revenueSeries = (clone $paymentQuery)->selectRaw('DATE(payment_date) AS day, SUM(amount) AS total')
            ->groupByRaw('DATE(payment_date)')->orderBy('day')->get()
            ->map(fn ($row) => ['label' => $row->day, 'value' => round((float) $row->total, 2)]);
        $squareBreakdown = (clone $paymentQuery)
            ->leftJoin('subscribers', 'subscribers.id', '=', 'payments.subscriber_id')
            ->leftJoin('squares', 'squares.id', '=', 'subscribers.square_id')
            ->select('squares.id', 'squares.name')
            ->selectRaw('SUM(payments.amount) AS total, COUNT(*) AS payment_count')
            ->groupBy('squares.id', 'squares.name')->orderBy('squares.name')->get()
            ->map(fn ($row) => [
                'key' => $row->id === null ? 'unassigned' : (string) $row->id,
                'label' => $row->name ?? 'بلا مربع',
                'value' => round((float) $row->total, 2),
                'count' => (int) $row->payment_count,
            ]);

        return response()->json([
            'filters' => ['from_date' => $from, 'to_date' => $to, 'square_id' => $squareId],
            'summary' => [
                'collection_total' => $collectionTotal,
                'invoices_total' => $invoicesTotal,
                'total_collected' => $collectionTotal,
                'total_invoiced' => $invoicesTotal,
                'total_outstanding' => round((float) $invoiceTotals->outstanding_total, 2),
                'overdue_amount' => round((float) $invoiceTotals->overdue_total, 2),
                'paid_invoices_count' => (int) $invoiceTotals->paid_count,
                'overdue_invoices_count' => (int) $invoiceTotals->overdue_count,
                'invoices_count' => (int) $invoiceTotals->invoices_count,
                'readings_count' => (clone $readingQuery)->count(),
                'consumption_units' => round((float) (clone $readingQuery)->sum('consumption_units'), 2),
            ],
            'account_balances' => $finance->accountBalances($squareId !== null ? (int) $squareId : null),
            'financial_movements' => $finance->movements($from, $to, $squareId !== null ? (int) $squareId : null, $collectionTotal),
            'collections_by_method' => (clone $paymentQuery)->select('payment_method', DB::raw('SUM(amount) as total'))
                ->groupBy('payment_method')->orderBy('payment_method')->get(),
            'invoices_by_status' => $statusTotals->map(fn ($row) => ['status' => $row->status, 'total' => (int) $row->total]),
            'revenue_series' => $revenueSeries,
            'status_breakdown' => $statusTotals->map(fn ($row) => [
                'key' => $row->status,
                'label' => $statusLabels[$row->status] ?? $row->status,
                'value' => round((float) $row->amount, 2),
                'count' => (int) $row->total,
            ]),
            'square_breakdown' => $squareBreakdown,
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
            'outstanding_amount' => round($outstanding, 2),
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
