<?php

namespace App\Http\Controllers\Api;

use App\Models\SubscriptionFee;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionFeeController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi(SubscriptionFee::class, 'viewAny');
        $query = SubscriptionFee::with(['subscriber:id,name,phone,square_id', 'meter:id,unique_code,path_number,tenant_name', 'invoice:id,bill_number,status'])
            ->latest('id');
        if ($request->filled('status')) $query->where('status', $request->query('status'));
        if ($request->filled('subscriber_id')) $query->where('subscriber_id', $request->integer('subscriber_id'));
        if ($request->filled('meter_id')) $query->where('meter_id', $request->integer('meter_id'));
        if ($request->filled('from_date')) $query->whereDate('created_at', '>=', $request->query('from_date'));
        if ($request->filled('to_date')) $query->whereDate('created_at', '<=', $request->query('to_date'));
        $total = (clone $query)->sum('amount');
        return response()->json([
            'data' => $query->paginate($this->perPage($request))->appends($request->query()),
            'total_sum' => round((float) $total, 2),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeApi(SubscriptionFee::class, 'create');
        $data = $request->validate([
            'subscriber_id' => ['required', 'integer', 'exists:subscribers,id'],
            'meter_id' => ['nullable', 'integer', 'exists:meters,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
        ]);
        if (! empty($data['meter_id']) && ! \App\Models\Meter::where('id', $data['meter_id'])->where('subscriber_id', $data['subscriber_id'])->exists()) {
            return response()->json(['message' => 'العداد المحدد لا يتبع هذا المشترك.'], 422);
        }
        $fee = SubscriptionFee::create($data + ['status' => 'uninvoiced']);
        return response()->json(['message' => 'تمت إضافة رسوم الاشتراك.', 'subscription_fee' => $fee->load(['subscriber', 'meter'])], 201);
    }

    public function show(int $id)
    {
        $this->authorizeApi(SubscriptionFee::class, 'view');
        return response()->json(['subscription_fee' => SubscriptionFee::with(['subscriber.square', 'meter', 'invoice'])->findOrFail($id)]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeApi(SubscriptionFee::class, 'update');
        $fee = SubscriptionFee::findOrFail($id);
        if ($fee->invoice_id || $fee->status !== 'uninvoiced') {
            return response()->json(['message' => 'لا يمكن تعديل رسوم تم ربطها بفاتورة.'], 409);
        }
        $data = $request->validate([
            'subscriber_id' => ['sometimes', 'required', 'integer', 'exists:subscribers,id'],
            'meter_id' => ['sometimes', 'nullable', 'integer', 'exists:meters,id'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);
        $subscriberId = $data['subscriber_id'] ?? $fee->subscriber_id;
        if (! empty($data['meter_id']) && ! \App\Models\Meter::where('id', $data['meter_id'])->where('subscriber_id', $subscriberId)->exists()) {
            return response()->json(['message' => 'العداد المحدد لا يتبع هذا المشترك.'], 422);
        }
        $fee->update($data);
        return response()->json(['message' => 'تم تحديث رسوم الاشتراك.', 'subscription_fee' => $fee->fresh(['subscriber', 'meter'])]);
    }

    public function destroy(int $id)
    {
        $this->authorizeApi(SubscriptionFee::class, 'delete');
        $fee = SubscriptionFee::findOrFail($id);
        if ($fee->invoice_id || $fee->status !== 'uninvoiced') {
            return response()->json(['message' => 'لا يمكن حذف رسوم مرتبطة بفاتورة.'], 409);
        }
        $fee->delete();
        return response()->json(['message' => 'تم حذف رسوم الاشتراك.']);
    }

    public function bulkDelete(Request $request)
    {
        $this->authorizeApi(SubscriptionFee::class, 'deleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:subscription_fees,id']]);
        $deleted = 0;
        $skipped = [];
        DB::transaction(function () use ($data, &$deleted, &$skipped) {
            foreach (SubscriptionFee::whereIn('id', $data['ids'])->lockForUpdate()->get() as $fee) {
                if ($fee->invoice_id || $fee->status !== 'uninvoiced') {
                    $skipped[] = $fee->id;
                    continue;
                }
                $fee->delete();
                $deleted++;
            }
        });
        return response()->json(['deleted_count' => $deleted, 'skipped_count' => count($skipped), 'skipped_ids' => $skipped]);
    }

    public function invoice(int $id, InvoiceService $invoiceService)
    {
        $this->authorizeApi(SubscriptionFee::class, 'update');
        $fee = SubscriptionFee::with(['subscriber', 'meter'])->findOrFail($id);
        if ($fee->status !== 'uninvoiced' || $fee->invoice_id) {
            return response()->json(['message' => 'رسوم الاشتراك مفوترة مسبقاً أو غير متاحة.'], 409);
        }
        try {
            $invoice = $invoiceService->generateInvoiceForSubscriptionFee($fee);
            return response()->json(['message' => 'تم إصدار الفاتورة.', 'invoice' => $invoice->load(['subscriber', 'meter', 'items'])], 201);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
