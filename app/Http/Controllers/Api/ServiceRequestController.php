<?php

namespace App\Http\Controllers\Api;

use App\Models\ServiceRequest;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceRequestController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi(ServiceRequest::class, 'viewAny');
        $query = ServiceRequest::with(['subscriber:id,name,phone,square_id', 'subscriber.square:id,name,code', 'subscriber.meters:id,subscriber_id,path_number', 'invoice:id,bill_number,status'])
            ->latest('service_date')->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('subscriber_id')) {
            $query->where('subscriber_id', $request->integer('subscriber_id'));
        }
        if ($request->filled('created_from')) {
            $query->whereDate('service_date', '>=', $request->query('created_from'));
        }
        if ($request->filled('created_until')) {
            $query->whereDate('service_date', '<=', $request->query('created_until'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('service_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('service_date', '<=', $request->query('to_date'));
        }
        $this->applySorting($query, $request, self::LIST_SORTS['service_requests']);
        $total = (clone $query)->sum('amount');
        $page = $query->paginate($this->perPage($request))->appends($request->query());

        return response()->json([
            'data' => $page->items(),
            'total_sum' => round((float) $total, 2),
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeApi(ServiceRequest::class, 'create');
        $data = $request->validate([
            'subscriber_id' => ['required', 'integer', 'exists:subscribers,id'],
            'service_type' => ['sometimes', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'service_date' => ['required', 'date'],
            'status' => ['sometimes', 'in:pending,completed,invoiced,paid'],
            'notes' => ['nullable', 'string'],
        ]);
        $data['service_type'] ??= 'water_tanker';
        $data['status'] ??= 'completed';
        $requestRecord = ServiceRequest::create($data);

        return response()->json(['message' => 'تم تسجيل طلب الخدمة.', 'service_request' => $requestRecord->load(['subscriber', 'invoice'])], 201);
    }

    public function show(int $id)
    {
        $this->authorizeApi(ServiceRequest::class, 'view');

        return response()->json(['service_request' => ServiceRequest::with(['subscriber.square', 'invoice'])->findOrFail($id)]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeApi(ServiceRequest::class, 'update');
        $record = ServiceRequest::findOrFail($id);
        $data = $request->validate([
            'subscriber_id' => ['sometimes', 'required', 'integer', 'exists:subscribers,id'],
            'service_type' => ['sometimes', 'required', 'string', 'max:50'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'service_date' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', 'in:pending,completed,invoiced,paid'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);
        if ($record->invoice_id && (
            (array_key_exists('amount', $data) && (float) $data['amount'] !== (float) $record->amount)
            || (array_key_exists('subscriber_id', $data) && (int) $data['subscriber_id'] !== (int) $record->subscriber_id)
        )) {
            return response()->json(['message' => 'لا يمكن تغيير مبلغ أو مشترك طلب مرتبط بفاتورة صادرة.'], 409);
        }
        $record->update($data);

        return response()->json(['message' => 'تم تحديث طلب الخدمة.', 'service_request' => $record->fresh(['subscriber', 'invoice'])]);
    }

    public function destroy(int $id)
    {
        $this->authorizeApi(ServiceRequest::class, 'delete');
        $record = ServiceRequest::findOrFail($id);
        if ($record->invoice_id || in_array($record->status, ['invoiced', 'paid'], true)) {
            return response()->json(['message' => 'لا يمكن حذف طلب مرتبط بفاتورة.'], 409);
        }
        $record->delete();

        return response()->json(['message' => 'تم حذف طلب الخدمة.']);
    }

    public function bulkDelete(Request $request)
    {
        $this->authorizeApi(ServiceRequest::class, 'deleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:service_requests,id']]);
        $deleted = 0;
        $skipped = [];
        DB::transaction(function () use ($data, &$deleted, &$skipped) {
            foreach (ServiceRequest::whereIn('id', $data['ids'])->lockForUpdate()->get() as $record) {
                if ($record->invoice_id || in_array($record->status, ['invoiced', 'paid'], true)) {
                    $skipped[] = $record->id;

                    continue;
                }
                $record->delete();
                $deleted++;
            }
        });

        return response()->json(['deleted_count' => $deleted, 'skipped_count' => count($skipped), 'skipped_ids' => $skipped]);
    }

    public function complete(int $id)
    {
        $this->authorizeApi(ServiceRequest::class, 'update');
        $record = ServiceRequest::findOrFail($id);
        if ($record->status !== 'pending') {
            return response()->json(['message' => 'يمكن إنجاز الطلب المعلق فقط.'], 409);
        }
        $record->update(['status' => 'completed']);

        return response()->json(['message' => 'تم إنجاز الطلب.', 'service_request' => $record]);
    }

    public function invoice(int $id, InvoiceService $invoiceService)
    {
        $this->authorizeApi(ServiceRequest::class, 'update');
        $record = ServiceRequest::with('subscriber')->findOrFail($id);
        if ($record->status !== 'completed' || $record->invoice_id) {
            return response()->json(['message' => 'يجب إكمال الطلب قبل إصدار الفاتورة.'], 409);
        }
        try {
            $invoice = $invoiceService->generateInvoiceForTankerFee($record);

            return response()->json(['message' => 'تم إصدار فاتورة الوايت.', 'invoice' => $invoice->load(['subscriber', 'items'])], 201);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
