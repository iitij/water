<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Services\InvoiceService;
use Illuminate\Http\Request;

/**
 * طلبات الوايت — SCR-20 (Endpoint 57 في الخطة الرئيسية 2.3).
 */
class ServiceRequestController extends Controller
{
    public function index(Request $request)
    {
        $query = ServiceRequest::with(['subscriber:id,name,phone', 'invoice:id,bill_number'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('subscriber_id'), fn ($q) => $q->where('subscriber_id', $request->subscriber_id))
            ->when($request->filled('created_from'), fn ($q) => $q->whereDate('service_date', '>=', $request->created_from))
            ->when($request->filled('created_until'), fn ($q) => $q->whereDate('service_date', '<=', $request->created_until))
            ->orderByDesc('service_date');

        $totalSum = (clone $query)->sum('amount');

        $requests = $request->filled('per_page')
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return response()->json([
            'data' => $requests instanceof \Illuminate\Contracts\Pagination\Paginator ? $requests->items() : $requests,
            'total_sum' => (float) $totalSum,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subscriber_id' => 'required|exists:subscribers,id',
            'amount' => 'required|numeric|min:0',
            'service_date' => 'required|date',
            'status' => 'nullable|in:pending,completed,invoiced,paid',
            'notes' => 'nullable|string',
        ]);
        $validated['service_type'] = 'water_tanker';
        $validated['status'] ??= 'completed';

        $serviceRequest = ServiceRequest::create($validated)->load('subscriber:id,name,phone');

        return response()->json(['message' => 'تم إضافة طلب الوايت بنجاح', 'service_request' => $serviceRequest], 201);
    }

    public function update(Request $request, int $id)
    {
        $serviceRequest = ServiceRequest::find($id);
        if (! $serviceRequest) {
            return response()->json(['message' => 'طلب الوايت غير موجود'], 404);
        }

        $validated = $request->validate([
            'subscriber_id' => 'sometimes|exists:subscribers,id',
            'amount' => 'sometimes|numeric|min:0',
            'service_date' => 'sometimes|date',
            'status' => 'sometimes|in:pending,completed,invoiced,paid',
            'notes' => 'nullable|string',
        ]);

        $serviceRequest->update($validated);

        return response()->json(['message' => 'تم تحديث طلب الوايت بنجاح', 'service_request' => $serviceRequest->load('subscriber:id,name,phone')]);
    }

    public function destroy(int $id)
    {
        $serviceRequest = ServiceRequest::find($id);
        if (! $serviceRequest) {
            return response()->json(['message' => 'طلب الوايت غير موجود'], 404);
        }
        $serviceRequest->delete();

        return response()->json(['message' => 'تم حذف طلب الوايت بنجاح']);
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $deleted = ServiceRequest::whereIn('id', $validated['ids'])->delete();

        return response()->json(['message' => 'تم حذف طلبات الوايت بنجاح', 'deleted_count' => $deleted]);
    }

    /** إجراء "إنجاز" (mark_completed): pending ← completed فقط. */
    public function complete(int $id)
    {
        $serviceRequest = ServiceRequest::find($id);
        if (! $serviceRequest) {
            return response()->json(['message' => 'طلب الوايت غير موجود'], 404);
        }
        if ($serviceRequest->status !== 'pending') {
            return response()->json(['message' => 'لا يمكن إنجاز طلب بحالته الحالية'], 400);
        }

        $serviceRequest->update(['status' => 'completed']);

        return response()->json(['message' => 'تم إنجاز الطلب بنجاح', 'service_request' => $serviceRequest]);
    }

    /** إصدار فاتورة وايت لهذا الطلب تحديداً (InvoiceService::generateInvoiceForTankerFee). */
    public function invoice(int $id, InvoiceService $invoiceService)
    {
        $serviceRequest = ServiceRequest::with('subscriber')->find($id);
        if (! $serviceRequest) {
            return response()->json(['message' => 'طلب الوايت غير موجود'], 404);
        }
        if (in_array($serviceRequest->status, ['invoiced', 'paid'], true)) {
            return response()->json(['message' => 'تم إصدار فاتورة مسبقاً لهذا الطلب'], 400);
        }

        $invoice = $invoiceService->generateInvoiceForTankerFee($serviceRequest);

        return response()->json(['message' => 'تم إصدار الفاتورة بنجاح', 'invoice' => $invoice], 201);
    }
}
