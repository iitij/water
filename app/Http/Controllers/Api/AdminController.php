<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $from = $filters['from_date'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to_date'] ?? now()->toDateString();
        $squareId = $filters['square_id'] ?? null;

        // payment_date عمود datetime (منذ 2026-10-23)، لذا يجب تحديد اليوم كاملاً
        // وإلا تُستبعد مدفوعات اليوم نفسه بعد منتصف الليل من التقرير.
        $paymentQuery = Payment::query()->whereBetween('payment_date', [
            $from.' 00:00:00',
            $to.' 23:59:59',
        ]);
        $invoiceQuery = Invoice::query()->whereBetween('bill_date', [$from, $to]);
        $readingQuery = Reading::query()->whereBetween('reading_date', [$from, $to]);
        if ($squareId) {
            $paymentQuery->whereHas('subscriber', fn ($q) => $q->where('square_id', $squareId));
            $invoiceQuery->whereHas('subscriber', fn ($q) => $q->where('square_id', $squareId));
            $readingQuery->whereHas('meter.subscriber', fn ($q) => $q->where('square_id', $squareId));
        }

        return response()->json([
            'filters' => ['from_date' => $from, 'to_date' => $to, 'square_id' => $squareId],
            'summary' => [
                'collection_total' => round((float) (clone $paymentQuery)->sum('amount'), 2),
                'invoices_total' => round((float) (clone $invoiceQuery)->sum('total_amount'), 2),
                'invoices_count' => (clone $invoiceQuery)->count(),
                'readings_count' => (clone $readingQuery)->count(),
                'consumption_units' => (int) (clone $readingQuery)->sum('consumption_units'),
            ],
            'collections_by_method' => (clone $paymentQuery)->select('payment_method', DB::raw('SUM(amount) as total'))
                ->groupBy('payment_method')->orderBy('payment_method')->get(),
            'invoices_by_status' => (clone $invoiceQuery)->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')->orderBy('status')->get(),
        ]);
    }

    private function overview(): array
    {
        $monthStart = now()->startOfMonth();
        $unpaid = Invoice::whereIn('status', ['issued', 'partially_paid', 'overdue']);
        $outstanding = abs((float) Subscriber::where('balance', '<', 0)->sum('balance'))
            + abs((float) Meter::where('balance', '<', 0)->sum('balance'));
        $subscriberSparkline = [];
        $collectionSparkline = [];
        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo)->toDateString();
            $subscriberSparkline[] = Subscriber::whereDate('created_at', $date)->count();
            $collectionSparkline[] = round((float) Payment::whereDate('payment_date', $date)->sum('amount'), 2);
        }

        return [
            'active_subscribers_count' => Subscriber::where('status', 'active')->count(),
            'total_subscribers_count' => Subscriber::count(),
            'outstanding_amount' => round($outstanding, 2),
            'monthly_collection' => round((float) Payment::whereDate('payment_date', '>=', $monthStart->toDateString())->sum('amount'), 2),
            'overdue_invoices_count' => Invoice::where('status', 'overdue')->count(),
            'unpaid_invoices_count' => (clone $unpaid)->count(),
            'readings_this_month' => Reading::whereDate('reading_date', '>=', $monthStart->toDateString())->count(),
            'subscribers_sparkline' => $subscriberSparkline,
            'collection_sparkline' => $collectionSparkline,
        ];
    }

    private function monthlyRevenueData(): array
    {
        $labels = [];
        $data = [];
        for ($offset = 5; $offset >= 0; $offset--) {
            $month = now()->subMonths($offset);
            $labels[] = $month->locale('ar')->translatedFormat('F Y');
            $data[] = round((float) Payment::whereYear('payment_date', $month->year)
                ->whereMonth('payment_date', $month->month)->sum('amount'), 2);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    private function latestInvoiceData()
    {
        return Invoice::with(['subscriber:id,name,phone,square_id', 'subscriber.square:id,name', 'meter:id,unique_code,path_number,tenant_name'])
            ->latest('bill_date')->latest('id')->limit(5)->get()
            ->map(fn (Invoice $invoice) => $this->decorateInvoice($invoice));
    }

    private function overdueInvoiceData()
    {
        return Invoice::with(['subscriber:id,name,phone,square_id', 'meter:id,unique_code,path_number,tenant_name'])
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
