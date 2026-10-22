<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscriber;
use App\Models\Reading;
use App\Models\Payment;
use App\Models\Invoice;
use Carbon\Carbon;

class AdminController extends Controller
{
    public function stats()
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();

        $totalSubscribers = Subscriber::count();
        
        $totalReadingsThisMonth = Reading::where('created_at', '>=', $startOfMonth)->count();
        
        $collectedThisMonth = Payment::where('created_at', '>=', $startOfMonth)->sum('amount');
        
        $unpaidInvoicesCount = Invoice::whereIn('status', ['issued', 'overdue', 'partially_paid'])->count();
        
        $unpaidAmountTotal = Invoice::whereIn('status', ['issued', 'overdue', 'partially_paid'])
            ->selectRaw('SUM(total_amount - amount_paid) as total_unpaid')
            ->value('total_unpaid') ?? 0;

        return response()->json([
            'subscribers_count' => $totalSubscribers,
            'readings_this_month' => $totalReadingsThisMonth,
            'collected_this_month' => $collectedThisMonth,
            'unpaid_invoices_count' => $unpaidInvoicesCount,
            'total_unpaid_amount' => $unpaidAmountTotal,
        ]);
    }
}
