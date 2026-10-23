<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscriber;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Meter;
use App\Models\SubscriptionFee;
use App\Models\ServiceRequest;

class SubscriberController extends Controller
{
    public function squares()
    {
        $squares = Square::orderBy('id', 'asc')->get(['id', 'name']);
        return response()->json($squares);
    }

    public function storeSquare(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $square = Square::create($validated);

        return response()->json([
            'message' => 'تم إضافة المربع بنجاح',
            'square' => $square,
        ], 201);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'square_id' => 'required|exists:squares,id',
            'status' => 'required|in:active,inactive,suspended',
            'subscription_start_date' => 'nullable|date',
            'address' => 'nullable|string',
        ]);

        $subscriber = Subscriber::create($validated);

        return response()->json([
            'message' => 'تم إضافة المشترك بنجاح',
            'subscriber' => $subscriber,
        ], 201);
    }

    public function storeMeter(Request $request)
    {
        $validated = $request->validate([
            'unique_code' => 'required|string|max:50|unique:meters,unique_code',
            'subscriber_id' => 'required|exists:subscribers,id',
            'tenant_name' => 'nullable|string|max:255',
            'tenant_phone' => 'nullable|string|max:50',
            'status' => 'required|in:active,inactive',
            'path_number' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) use ($request) {
                    if ($value !== null) {
                        $subscriber = \App\Models\Subscriber::find($request->input('subscriber_id'));
                        if ($subscriber) {
                            $exists = \App\Models\Meter::where('path_number', $value)
                                ->whereHas('subscriber', function($q) use ($subscriber) {
                                    $q->where('square_id', $subscriber->square_id);
                                })
                                ->exists();
                            if ($exists) {
                                $fail('رقم المسار مستخدم بالفعل لعداد آخر في نفس المربع السكني.');
                            }
                        }
                    }
                }
            ],
        ]);

        $meter = Meter::create($validated);

        return response()->json([
            'message' => 'تم إضافة العداد بنجاح',
            'meter' => $meter,
        ], 201);
    }

    public function storeSubscriptionFee(Request $request)
    {
        $validated = $request->validate([
            'subscriber_id' => 'required|exists:subscribers,id',
            'meter_id' => 'nullable|exists:meters,id',
            'amount' => 'required|numeric|min:0',
            'status' => 'required|in:uninvoiced,invoiced,partially_paid,paid',
            'notes' => 'nullable|string',
        ]);

        $subscriptionFee = SubscriptionFee::create($validated);

        return response()->json([
            'message' => 'تم إضافة رسوم الاشتراك بنجاح',
            'subscription_fee' => $subscriptionFee,
        ], 201);
    }

    public function storeServiceRequest(Request $request)
    {
        $validated = $request->validate([
            'subscriber_id' => 'required|exists:subscribers,id',
            'amount' => 'required|numeric|min:0',
            'service_date' => 'required|date',
            'status' => 'required|in:pending,completed,invoiced,paid',
            'notes' => 'nullable|string',
        ]);

        $validated['service_type'] = 'water_tanker';

        $serviceRequest = ServiceRequest::create($validated);

        return response()->json([
            'message' => 'تم إضافة طلب الوايت بنجاح',
            'service_request' => $serviceRequest,
        ], 201);
    }

    public function index(Request $request)
    {
        $query = Subscriber::with(['meters', 'square:id,name'])->withCount('meters');
        if ($request->has('q') && !empty($request->q)) {
            $q = $request->q;
            $query->where(function($subQuery) use ($q) {
                $subQuery->where('name', 'like', "%{$q}%")
                         ->orWhere('phone', 'like', "%{$q}%")
                         ->orWhereHas('meters', function($mq) use ($q) {
                             $mq->where('unique_code', 'like', "%{$q}%")
                                ->orWhere('tenant_name', 'like', "%{$q}%");
                         });
            });
        }
        $query->when($request->filled('square_id'), fn ($q) => $q->where('square_id', $request->square_id))
              ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
              ->orderBy('name', 'asc');

        $map = function ($sub) {
            $totalDebit = $sub->getOutstandingBalance() + $sub->meters->sum(fn($m) => $m->getOutstandingBalance());
            $totalCredit = ($sub->balance > 0 ? (float) $sub->balance : 0) + $sub->meters->sum(fn($m) => $m->balance > 0 ? (float) $m->balance : 0);

            return [
                'id' => $sub->id,
                'name' => $sub->name,
                'phone' => $sub->phone,
                'address' => $sub->address,
                'status' => $sub->status,
                'square_id' => $sub->square_id,
                'square' => $sub->square ? ['id' => $sub->square->id, 'name' => $sub->square->name] : null,
                'meters_count' => $sub->meters_count ?? $sub->meters->count(),
                'subscription_start_date' => $sub->subscription_start_date?->format('Y-m-d'),
                'balance' => $totalDebit,
                'debit_balance' => $totalDebit,
                'credit_balance' => $totalCredit,
                'account_balance' => $totalCredit > 0 ? $totalCredit : ($totalDebit > 0 ? -$totalDebit : 0),
                'meter_number' => $sub->meters->first()?->unique_code ?? 'بدون عداد',
            ];
        };

        if ($request->filled('per_page')) {
            $page = $query->paginate((int) $request->per_page);
            $page->getCollection()->transform($map);
            return response()->json($page);
        }

        return response()->json($query->get()->map($map));
    }

    /** الملف المالي الشامل 360° — SCR-07 (Endpoint 14): ملخص مالي معزول + العلاقات الخمس. */
    public function show(int $id)
    {
        $subscriber = Subscriber::with([
            'square:id,name',
            'meters' => fn ($q) => $q->withCount('readings'),
            'invoices' => fn ($q) => $q->latest('bill_date'),
            'payments' => fn ($q) => $q->latest('payment_date'),
            'subscriptionFees.meter:id,unique_code',
            'subscriptionFees.invoice:id,bill_number',
            'serviceRequests.invoice:id,bill_number',
        ])->find($id);

        if (!$subscriber) {
            return response()->json(['message' => 'المشترك غير موجود'], 404);
        }

        $meters = $subscriber->meters->map(fn ($m) => [
            'id' => $m->id,
            'unique_code' => $m->unique_code,
            'path_number' => $m->path_number,
            'status' => $m->status,
            'tenant_name' => $m->tenant_name,
            'tenant_phone' => $m->tenant_phone,
            'balance' => (float) $m->balance,
            'meter_credit' => $m->balance > 0 ? (float) $m->balance : 0.0,
            'meter_debit' => $m->getOutstandingBalance(),
            'readings_count' => $m->readings_count ?? 0,
        ]);

        $totalCredit = ($subscriber->balance > 0 ? (float) $subscriber->balance : 0)
            + $meters->sum('meter_credit');
        $totalDebit = $subscriber->getOutstandingBalance() + $meters->sum('meter_debit');

        $payload = $subscriber->toArray();
        $payload['meters'] = $meters;
        $payload['financial_summary'] = [
            'general_balance' => (float) $subscriber->balance,
            'total_credit_balance' => $totalCredit,
            'total_debit_balance' => $totalDebit,
            'remaining_subscription_fee' => $subscriber->getRemainingSubscriptionFee(),
            'subscription_fee_status' => $subscriber->getSubscriptionFeeStatus(),
        ];

        return response()->json($payload);
    }

    public function destroy(int $id)
    {
        $subscriber = Subscriber::find($id);
        if (!$subscriber) {
            return response()->json(['message' => 'المشترك غير موجود'], 404);
        }
        $subscriber->delete();

        return response()->json(['message' => 'تم حذف المشترك بنجاح']);
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $deleted = Subscriber::whereIn('id', $validated['ids'])->delete();

        return response()->json(['message' => 'تم حذف المشتركين بنجاح', 'deleted_count' => $deleted]);
    }

    /**
     * سجل الحركات المالية للمشترك — تبويب كشف الحساب في SCR-07 (Endpoint 18).
     * مطابق لـ SubscriberTransactionsTable: ?meter_id=general (meter_id IS NULL) أو ?meter_id={id}،
     * مع الرصيد التراكمي (running balance) محسوباً تصاعدياً ثم يُعرض تنازلياً.
     */
    public function transactions(Request $request, int $id)
    {
        $subscriber = Subscriber::find($id);
        if (!$subscriber) {
            return response()->json(['message' => 'المشترك غير موجود'], 404);
        }

        $query = \App\Models\Transaction::with('meter:id,unique_code,tenant_name')
            ->where('subscriber_id', $id)
            ->when($request->filled('meter_id'), function ($q) use ($request) {
                $request->meter_id === 'general'
                    ? $q->whereNull('meter_id')
                    : $q->where('meter_id', $request->meter_id);
            })
            ->orderBy('created_at')->orderBy('id');

        $running = 0.0;
        $rows = $query->get()->map(function ($t) use (&$running) {
            $running += (float) $t->amount;
            return [
                'id' => $t->id,
                'created_at' => $t->created_at?->format('Y-m-d H:i'),
                'type' => $t->type,
                'meter_id' => $t->meter_id,
                'party' => $t->meter_id
                    ? 'عداد رقم: ' . $t->meter_id . ($t->meter?->tenant_name ? ' - ' . $t->meter->tenant_name : '')
                    : 'الحساب العام للمشترك',
                'amount' => (float) $t->amount,
                'running_balance' => round($running, 2),
                'description' => $t->description,
            ];
        });

        return response()->json($rows->sortByDesc('id')->values());
    }

    /** تحصيل دفعة مباشرة للمشترك — SCR-05/SCR-07 (Endpoint 19، PaymentService FIFO). */
    public function collectPayment(Request $request, int $id)
    {
        $subscriber = Subscriber::find($id);
        if (!$subscriber) {
            return response()->json(['message' => 'المشترك غير موجود'], 404);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'invoice_id' => 'nullable|exists:invoices,id',
            'payment_method' => 'nullable|in:cash,bank_transfer,check',
            'reference_number' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $payment = app(\App\Services\PaymentService::class)->collectPayment(
            subscriber: $subscriber,
            amount: (float) $validated['amount'],
            invoiceId: $validated['invoice_id'] ?? null,
            paymentMethod: $validated['payment_method'] ?? 'cash',
            referenceNumber: $validated['reference_number'] ?? null,
            notes: $validated['notes'] ?? null,
            reason: $validated['reason'] ?? null,
            userId: $request->user()?->id,
        );

        return response()->json(['message' => 'تم تحصيل الدفعة بنجاح', 'payment' => $payment], 201);
    }

    public function update(Request $request, int $id)
    {
        $subscriber = Subscriber::find($id);
        if (!$subscriber) {
            return response()->json(['message' => 'المشترك غير موجود'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'square_id' => 'required|exists:squares,id',
            'status' => 'required|in:active,inactive,suspended',
            'address' => 'nullable|string',
        ]);

        $subscriber->update($validated);

        return response()->json([
            'message' => 'تم تحديث المشترك بنجاح',
            'subscriber' => $subscriber,
        ]);
    }

    public function updateMeter(Request $request, int $id)
    {
        $meter = Meter::find($id);
        if (!$meter) {
            return response()->json(['message' => 'العداد غير موجود'], 404);
        }

        $validated = $request->validate([
            'unique_code' => 'required|string|max:50|unique:meters,unique_code,' . $id,
            'path_number' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) use ($meter) {
                    if ($value !== null) {
                        $exists = \App\Models\Meter::where('path_number', $value)
                            ->where('id', '!=', $meter->id)
                            ->whereHas('subscriber', function($q) use ($meter) {
                                $q->where('square_id', $meter->subscriber->square_id);
                            })
                            ->exists();
                        if ($exists) {
                            $fail('رقم المسار مستخدم بالفعل لعداد آخر في نفس المربع السكني.');
                        }
                    }
                }
            ],
            'tenant_name' => 'nullable|string|max:255',
            'tenant_phone' => 'nullable|string|max:50',
            'status' => 'required|in:active,inactive',
        ]);

        $meter->update($validated);

        return response()->json([
            'message' => 'تم تحديث العداد بنجاح',
            'meter' => $meter,
        ]);
    }

    public function statement(int $id)
    {
        $subscriber = Subscriber::find($id);
        if (!$subscriber) {
            return response()->json(['message' => 'المشترك غير موجود'], 404);
        }

        $invoices = Invoice::where('subscriber_id', $id)
            ->orderBy('bill_date', 'desc')
            ->get()
            ->map(function ($inv) {
                return [
                    'type' => 'invoice',
                    'date' => $inv->bill_date->format('Y-m-d'),
                    'description' => 'فاتورة استهلاك (' . $inv->bill_number . ')',
                    'amount' => $inv->total_amount,
                    'is_debit' => true,
                ];
            });

        $payments = Payment::where('subscriber_id', $id)
            ->orderBy('payment_date', 'desc')
            ->get()
            ->map(function ($pay) {
                return [
                    'type' => 'payment',
                    'date' => $pay->payment_date->format('Y-m-d'),
                    'description' => 'سند قبض (' . $pay->receipt_number . ')',
                    'amount' => $pay->amount,
                    'is_debit' => false,
                ];
            });

        $transactions = $invoices->concat($payments)->sortByDesc('date')->values();

        return response()->json([
            'subscriber' => [
                'name' => $subscriber->name,
                'balance' => $subscriber->getOutstandingBalance(),
            ],
            'transactions' => $transactions,
        ]);
    }

    public function invoiceSubscriptionFee(int $id)
    {
        $fee = SubscriptionFee::with(['subscriber', 'meter'])->find($id);
        if (!$fee) {
            return response()->json(['message' => 'رسوم الاشتراك غير موجودة'], 404);
        }

        if ($fee->status !== 'uninvoiced') {
            return response()->json(['message' => 'هذه الرسوم مفوترة مسبقاً'], 400);
        }

        $subscriber = $fee->subscriber;
        $sumAmount = \App\Models\SubscriptionFee::where('subscriber_id', $subscriber->id)
            ->where('status', 'uninvoiced')
            ->sum('amount');

        $invoiceService = app(\App\Services\InvoiceService::class);
        $invoice = $invoiceService->generateInvoice(
            subscriber: $subscriber,
            meter: null,
            subscriptionInstallment: (float) $sumAmount,
            notes: null,
            tankerInstallment: null,
            includeReadings: false,
            invoiceType: 'subscription'
        );
        
        $invoiceArray = $invoice->toArray();
        $invoiceArray['user'] = ['name' => request()->user() ? request()->user()->name : 'إدارة المشروع'];
        $invoiceArray['pdf_url'] = url('/invoices/' . $invoice->id . '/print');

        return response()->json([
            'message' => 'تم إصدار الفاتورة بنجاح',
            'invoice' => $invoiceArray,
        ], 201);
    }

    public function invoiceServiceRequest(int $id)
    {
        $request = ServiceRequest::with('subscriber')->find($id);
        if (!$request) {
            return response()->json(['message' => 'طلب الوايت غير موجود'], 404);
        }

        if (in_array($request->status, ['invoiced', 'paid'])) {
            return response()->json(['message' => 'تم إصدار فاتورة مسبقاً لهذا الطلب'], 400);
        }

        $subscriber = $request->subscriber;
        $sumAmount = \App\Models\ServiceRequest::where('subscriber_id', $subscriber->id)
            ->where('status', 'completed')
            ->sum('amount');

        $invoiceService = app(\App\Services\InvoiceService::class);
        $invoice = $invoiceService->generateInvoice(
            subscriber: $subscriber,
            meter: null,
            subscriptionInstallment: null,
            notes: null,
            tankerInstallment: (float) $sumAmount,
            includeReadings: false,
            invoiceType: 'tanker'
        );
        
        $invoiceArray = $invoice->toArray();
        $invoiceArray['user'] = ['name' => request()->user() ? request()->user()->name : 'إدارة المشروع'];
        $invoiceArray['pdf_url'] = url('/invoices/' . $invoice->id . '/print');

        return response()->json([
            'message' => 'تم إصدار الفاتورة بنجاح',
            'invoice' => $invoiceArray,
        ], 201);
    }
}
