<?php

namespace App\Http\Controllers\Api;

use App\Models\Meter;
use App\Models\SubscriptionFee;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionFeeController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi(SubscriptionFee::class, 'viewAny');
        $query = SubscriptionFee::with(['subscriber:id,name,phone,square_id', 'subscriber.square:id,name,code', 'subscriber.meters:id,subscriber_id,path_number', 'meter:id,unique_code,path_number,tenant_name', 'invoice:id,bill_number,status'])
            ->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('subscriber_id')) {
            $query->where('subscriber_id', $request->integer('subscriber_id'));
        }
        if ($request->filled('meter_id')) {
            $query->where('meter_id', $request->integer('meter_id'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->query('to_date'));
        }
        $this->applySorting($query, $request, self::LIST_SORTS['subscription_fees']);
        $total = (clone $query)->sum('amount');
        $invoiced = (clone $query)->whereIn('status', ['invoiced', 'partially_paid', 'paid'])->sum('amount');
        $page = $query->paginate($this->perPage($request))->appends($request->query());

        return response()->json([
            'data' => $page->items(),
            'total_sum' => round((float) $total, 2),
            'invoiced_sum' => round((float) $invoiced, 2),
            'remaining_sum' => round((float) ($total - $invoiced), 2),
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeApi(SubscriptionFee::class, 'create');
        $data = $request->validate([
            'subscriber_id' => ['required', 'integer', 'exists:subscribers,id'],
            'meter_id' => ['nullable', 'integer', 'exists:meters,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'status' => ['sometimes', 'in:uninvoiced,invoiced,partially_paid,paid'],
            'notes' => ['nullable', 'string'],
        ]);
        if (! empty($data['meter_id']) && ! Meter::where('id', $data['meter_id'])->where('subscriber_id', $data['subscriber_id'])->exists()) {
            return response()->json(['message' => 'العداد المحدد لا يتبع هذا المشترك.'], 422);
        }
        $data['status'] ??= 'uninvoiced';
        $fee = SubscriptionFee::create($data);

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
        $data = $request->validate([
            'subscriber_id' => ['sometimes', 'required', 'integer', 'exists:subscribers,id'],
            'meter_id' => ['sometimes', 'nullable', 'integer', 'exists:meters,id'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'status' => ['sometimes', 'required', 'in:uninvoiced,invoiced,partially_paid,paid'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);
        if ($fee->invoice_id && (
            (array_key_exists('amount', $data) && (float) $data['amount'] !== (float) $fee->amount)
            || (array_key_exists('subscriber_id', $data) && (int) $data['subscriber_id'] !== (int) $fee->subscriber_id)
        )) {
            return response()->json(['message' => 'لا يمكن تغيير مبلغ أو مشترك رسوم مرتبطة بفاتورة صادرة.'], 409);
        }
        $subscriberId = $data['subscriber_id'] ?? $fee->subscriber_id;
        if (! empty($data['meter_id']) && ! Meter::where('id', $data['meter_id'])->where('subscriber_id', $subscriberId)->exists()) {
            return response()->json(['message' => 'العداد المحدد لا يتبع هذا المشترك.'], 422);
        }
        $fee->update($data);

        return response()->json(['message' => 'تم تحديث رسوم الاشتراك.', 'subscription_fee' => $fee->fresh(['subscriber', 'meter', 'invoice'])]);
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
