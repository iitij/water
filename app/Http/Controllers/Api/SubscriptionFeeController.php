<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionFee;
use App\Services\InvoiceService;
use Illuminate\Http\Request;

/**
 * رسوم الاشتراك — SCR-19 (Endpoint 56 في الخطة الرئيسية 2.3).
 */
class SubscriptionFeeController extends Controller
{
    public function index(Request $request)
    {
        $query = SubscriptionFee::with(['subscriber:id,name', 'meter:id,unique_code', 'invoice:id,bill_number'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('subscriber_id'), fn ($q) => $q->where('subscriber_id', $request->subscriber_id))
            ->latest();

        // الإجماليات على كامل النطاق المفلتر (Sum Summarizer) قبل التصفح.
        $totalSum = (clone $query)->sum('amount');
        $invoicedSum = (clone $query)->whereIn('status', ['invoiced', 'partially_paid', 'paid'])->sum('amount');

        $fees = $request->filled('per_page')
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return response()->json([
            'data' => $fees instanceof \Illuminate\Contracts\Pagination\Paginator ? $fees->items() : $fees,
            'total_sum' => (float) $totalSum,
            'invoiced_sum' => (float) $invoicedSum,
            'remaining_sum' => (float) ($totalSum - $invoicedSum),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subscriber_id' => 'required|exists:subscribers,id',
            'meter_id' => 'nullable|exists:meters,id',
            'amount' => 'required|numeric|min:0',
            'status' => 'nullable|in:uninvoiced,invoiced,partially_paid,paid',
            'notes' => 'nullable|string',
        ]);
        $validated['status'] ??= 'uninvoiced';

        $fee = SubscriptionFee::create($validated)->load(['subscriber:id,name', 'meter:id,unique_code']);

        return response()->json(['message' => 'تم إضافة رسوم الاشتراك بنجاح', 'subscription_fee' => $fee], 201);
    }

    public function update(Request $request, int $id)
    {
        $fee = SubscriptionFee::find($id);
        if (! $fee) {
            return response()->json(['message' => 'رسوم الاشتراك غير موجودة'], 404);
        }

        $validated = $request->validate([
            'subscriber_id' => 'sometimes|exists:subscribers,id',
            'meter_id' => 'nullable|exists:meters,id',
            'amount' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:uninvoiced,invoiced,partially_paid,paid',
            'notes' => 'nullable|string',
        ]);

        $fee->update($validated);

        return response()->json(['message' => 'تم تحديث رسوم الاشتراك بنجاح', 'subscription_fee' => $fee->load(['subscriber:id,name', 'meter:id,unique_code'])]);
    }

    public function destroy(int $id)
    {
        $fee = SubscriptionFee::find($id);
        if (! $fee) {
            return response()->json(['message' => 'رسوم الاشتراك غير موجودة'], 404);
        }
        $fee->delete();

        return response()->json(['message' => 'تم حذف رسوم الاشتراك بنجاح']);
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $deleted = SubscriptionFee::whereIn('id', $validated['ids'])->delete();

        return response()->json(['message' => 'تم حذف رسوم الاشتراك بنجاح', 'deleted_count' => $deleted]);
    }

    /** إصدار فاتورة اشتراك لهذه الرسوم تحديداً (InvoiceService::generateInvoiceForSubscriptionFee). */
    public function invoice(int $id, InvoiceService $invoiceService)
    {
        $fee = SubscriptionFee::with(['subscriber', 'meter'])->find($id);
        if (! $fee) {
            return response()->json(['message' => 'رسوم الاشتراك غير موجودة'], 404);
        }
        if ($fee->status !== 'uninvoiced') {
            return response()->json(['message' => 'هذه الرسوم مفوترة مسبقاً'], 400);
        }

        $invoice = $invoiceService->generateInvoiceForSubscriptionFee($fee);

        return response()->json(['message' => 'تم إصدار الفاتورة بنجاح', 'invoice' => $invoice], 201);
    }
}
