<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SendWhatsAppInvoiceJob;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\ServiceRequest;
use App\Models\Subscriber;
use App\Models\SubscriptionFee;
use App\Models\Tariff;
use App\Models\Transaction;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\TransactionService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelPdf\Enums\Unit;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class InvoiceController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi(Invoice::class, 'viewAny');
        $trashed = (string) $request->query('trashed', 'without');
        $query = match ($trashed) {
            'with' => Invoice::withTrashed(),
            'only' => Invoice::onlyTrashed(),
            default => Invoice::query(),
        };
        $query->with([
            'subscriber:id,name,phone,square_id',
            'subscriber.square:id,name,code',
            'meter:id,unique_code,path_number',
        ])->latest('bill_date')->latest('id');

        if ($request->filled('status') && $request->query('status') !== 'all') {
            if ($request->query('status') === 'unpaid') {
                $query->whereIn('status', ['issued', 'partially_paid', 'overdue']);
            } else {
                $query->where('status', $request->query('status'));
            }
        }
        if ($request->filled('invoice_type')) {
            $query->where('invoice_type', $request->query('invoice_type'));
        }
        if ($request->filled('subscriber_id')) {
            $query->where('subscriber_id', $request->integer('subscriber_id'));
        }
        if ($request->filled('square_id')) {
            $query->whereHas('subscriber', fn ($q) => $q->where('square_id', $request->integer('square_id')));
        }
        if ($request->filled('billing_period')) {
            $period = explode('|', (string) $request->query('billing_period'), 2);
            if (count($period) === 2) {
                $query->whereDate('billing_period_start', $period[0])->whereDate('billing_period_end', $period[1]);
            }
        }
        if ($request->filled('from_date')) {
            $query->whereDate('bill_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('bill_date', '<=', $request->query('to_date'));
        }
        if ($search = trim((string) $request->query('q', $request->query('search', $request->query('query', ''))))) {
            $normalized = $this->normalizeDigits($search);
            if ($path = $this->parseCompoundSearch($normalized)) {
                [$squareId, $pathNumber] = $path;
                $query->whereHas('subscriber', fn ($q) => $q->where('square_id', $squareId))
                    ->whereHas('meter', fn ($q) => $q->where('path_number', $pathNumber));
            } else {
                $query->where(function ($builder) use ($search, $normalized) {
                    $builder->where('bill_number', 'like', "%{$search}%")
                        ->orWhereHas('subscriber.square', fn ($square) => $square->where('code', 'like', "%{$search}%"))
                        ->orWhereHas('subscriber', function ($subscriberQuery) use ($search) {
                            $subscriberQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        })
                        ->orWhereHas('meter', function ($meterQuery) use ($search, $normalized) {
                            $meterQuery->where('unique_code', 'like', "%{$search}%")
                                ->orWhere('tenant_name', 'like', "%{$search}%")
                                ->orWhere('tenant_phone', 'like', "%{$search}%");
                            if (ctype_digit($normalized)) {
                                $meterQuery->orWhere('path_number', (int) $normalized);
                            }
                        });
                });
            }
        }

        $this->applySorting($query, $request, self::LIST_SORTS['invoices']);

        return $query->paginate($this->perPage($request))->through(fn (Invoice $invoice) => $this->decorate($invoice))
            ->appends($request->query());
    }

    public function unpaid(Request $request)
    {
        $this->authorizeApi(Invoice::class, 'viewAny');
        $query = Invoice::with(['subscriber.square', 'subscriber.meters:id,subscriber_id,path_number', 'meter'])
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])->latest('bill_date')->latest('id');
        if ($request->filled('square_id')) {
            $query->whereHas('subscriber', fn ($q) => $q->where('square_id', $request->integer('square_id')));
        }
        if ($request->filled('q') || $request->filled('query')) {
            $request->query->set('status', 'unpaid');

            return $this->search($request);
        }

        $this->applySorting($query, $request, self::LIST_SORTS['invoices']);

        return $query->paginate($this->perPage($request, 50))->through(fn (Invoice $invoice) => $this->decorate($invoice))
            ->appends($request->query());
    }

    public function unpaidInvoices(Request $request)
    {
        return $this->unpaid($request);
    }

    public function search(Request $request)
    {
        return $this->index($request);
    }

    public function billingPeriods()
    {
        $this->authorizeApi(Invoice::class, 'viewAny');
        $periods = Invoice::query()->whereNotNull('billing_period_start')->whereNotNull('billing_period_end')
            ->select('billing_period_start', 'billing_period_end')->distinct()
            ->orderByDesc('billing_period_start')->get()
            ->map(fn (Invoice $invoice) => [
                'billing_period_start' => optional($invoice->billing_period_start)->format('Y-m-d'),
                'billing_period_end' => optional($invoice->billing_period_end)->format('Y-m-d'),
                'value' => optional($invoice->billing_period_start)->format('Y-m-d').'|'.optional($invoice->billing_period_end)->format('Y-m-d'),
            ]);

        return response()->json(['data' => $periods]);
    }

    public function show(int $id)
    {
        $this->authorizeApi(Invoice::class, 'view');
        $invoice = Invoice::withTrashed()->with([
            'subscriber.square', 'meter', 'items', 'readings',
            'payments' => fn ($q) => $q->with(['user:id,name', 'meter:id,unique_code,tenant_name'])->latest('payment_date'),
        ])->findOrFail($id);

        return response()->json(['invoice' => $this->decorate($invoice)]);
    }

    public function store(Request $request)
    {
        $this->authorizeApi(Invoice::class, 'create');
        $data = $request->validate([
            'subscriber_id' => ['required', 'integer', 'exists:subscribers,id'],
            'meter_id' => ['nullable', 'integer', 'exists:meters,id'],
            'invoice_type' => ['sometimes', 'in:meter,subscription,tanker'],
            'bill_number' => ['nullable', 'string', 'max:50', 'unique:invoices,bill_number'],
            'bill_date' => ['nullable', 'date'],
            'billing_period_start' => ['nullable', 'date'],
            'billing_period_end' => ['nullable', 'date', 'after_or_equal:billing_period_start'],
            'consumption_fee' => ['sometimes', 'numeric', 'min:0'],
            'subscription_installment' => ['sometimes', 'numeric', 'min:0'],
            'tanker_charges' => ['sometimes', 'numeric', 'min:0'],
            'previous_balance' => ['sometimes', 'numeric', 'min:0'],
            'credit_applied' => ['sometimes', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
        $subscriber = Subscriber::findOrFail($data['subscriber_id']);
        $meter = ! empty($data['meter_id']) ? Meter::where('subscriber_id', $subscriber->id)->findOrFail($data['meter_id']) : null;
        $isConsumption = (float) ($data['consumption_fee'] ?? 0) > 0;
        $invoiceType = $data['invoice_type'] ?? (($meter || $isConsumption) ? 'meter' : 'subscription');
        if ($isConsumption && $invoiceType !== 'meter') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'invoice_type' => ['لا يمكن إضافة رسوم استهلاك إلى فاتورة خدمات غير مخصصة للاستهلاك.'],
            ]);
        }
        if ($invoiceType === 'meter') {
            $meter = app(\App\Services\CurrentMeterBillingGuard::class)->resolve($subscriber, $meter);
        }
        $components = (float) ($data['consumption_fee'] ?? 0) + (float) ($data['subscription_installment'] ?? 0) + (float) ($data['tanker_charges'] ?? 0);
        $invoice = DB::transaction(function () use ($data, $subscriber, $meter, $components, $invoiceType) {
            $invoice = Invoice::create([
                'subscriber_id' => $subscriber->id,
                'meter_id' => $meter?->id,
                'invoice_type' => $invoiceType,
                'bill_number' => $data['bill_number'] ?? Invoice::generateBillNumber(),
                'bill_date' => $data['bill_date'] ?? now()->toDateString(),
                'billing_period_start' => $data['billing_period_start'] ?? now()->startOfMonth()->toDateString(),
                'billing_period_end' => $data['billing_period_end'] ?? now()->toDateString(),
                'consumption_fee' => $data['consumption_fee'] ?? 0,
                'subscription_installment' => $data['subscription_installment'] ?? 0,
                'tanker_charges' => $data['tanker_charges'] ?? 0,
                'previous_balance' => $data['previous_balance'] ?? 0,
                'credit_applied' => $data['credit_applied'] ?? 0,
                'total_amount' => $data['total_amount'] ?? $components,
                'amount_paid' => 0,
                'status' => 'issued',
                'notes' => $data['notes'] ?? null,
            ]);
            $this->syncItems($invoice);
            app(TransactionService::class)->recordTransaction(
                subscriber: $subscriber,
                type: 'invoice',
                amount: -(float) $invoice->total_amount,
                description: 'إصدار فاتورة رقم '.$invoice->bill_number,
                reference: $invoice,
                meter: $meter,
            );

            return $invoice;
        });

        return response()->json(['message' => 'تم إنشاء الفاتورة بنجاح.', 'invoice' => $this->decorate($invoice->load(['subscriber.square', 'meter', 'items']))], 201);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeApi(Invoice::class, 'update');
        $invoice = Invoice::findOrFail($id);
        $oldSubscriber = $invoice->subscriber;
        $oldMeter = $invoice->meter;
        $oldMeterId = $invoice->meter_id;
        $data = $request->validate([
            'subscriber_id' => ['sometimes', 'required', 'integer', 'exists:subscribers,id'],
            'meter_id' => ['sometimes', 'nullable', 'integer', 'exists:meters,id'],
            'invoice_type' => ['sometimes', 'in:meter,subscription,tanker'],
            'bill_date' => ['sometimes', 'required', 'date'],
            'billing_period_start' => ['sometimes', 'nullable', 'date'],
            'billing_period_end' => ['sometimes', 'nullable', 'date'],
            'consumption_fee' => ['sometimes', 'numeric', 'min:0'],
            'subscription_installment' => ['sometimes', 'numeric', 'min:0'],
            'tanker_charges' => ['sometimes', 'numeric', 'min:0'],
            'previous_balance' => ['sometimes', 'numeric', 'min:0'],
            'credit_applied' => ['sometimes', 'numeric', 'min:0'],
            'total_amount' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);
        $newSubscriberId = (int) ($data['subscriber_id'] ?? $invoice->subscriber_id);
        if (! empty($data['meter_id']) && ! Meter::where('id', $data['meter_id'])->where('subscriber_id', $newSubscriberId)->exists()) {
            return response()->json(['message' => 'العداد المحدد لا يتبع المشترك المحدد.'], 422);
        }

        // Preserve historical invoices, but do not move new consumption onto a retired meter.
        if (array_key_exists('meter_id', $data) && $data['meter_id'] !== null
            && (int) $data['meter_id'] !== (int) ($oldMeterId ?? 0)
            && ! Meter::findOrFail($data['meter_id'])->isCurrent()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'meter_id' => ['لا يمكن نقل الفاتورة إلى عداد منزوع.'],
            ]);
        }
        DB::transaction(function () use ($invoice, $data, $oldSubscriber, $oldMeter, $oldMeterId) {
            if (array_key_exists('subscriber_id', $data) && ! array_key_exists('meter_id', $data) && $invoice->meter_id) {
                $data['meter_id'] = null;
            }
            $invoice->fill($data);
            if (! array_key_exists('total_amount', $data) && array_intersect(array_keys($data), ['consumption_fee', 'subscription_installment', 'tanker_charges'])) {
                $invoice->total_amount = (float) $invoice->consumption_fee + (float) $invoice->subscription_installment + (float) $invoice->tanker_charges;
            }
            $invoice->save();
            $invoice->refresh();
            $this->syncItems($invoice);

            $transaction = Transaction::where('reference_type', Invoice::class)->where('reference_id', $invoice->id)->first();
            if ($transaction) {
                $transaction->update([
                    'subscriber_id' => $invoice->subscriber_id,
                    'meter_id' => $invoice->meter_id,
                    'amount' => -(float) $invoice->total_amount,
                    'description' => 'إصدار فاتورة رقم '.$invoice->bill_number,
                ]);
            } else {
                app(TransactionService::class)->recordTransaction(
                    subscriber: $invoice->subscriber,
                    type: 'invoice',
                    amount: -(float) $invoice->total_amount,
                    description: 'إصدار فاتورة رقم '.$invoice->bill_number,
                    reference: $invoice,
                    meter: $invoice->meter,
                );
            }

            $transactions = app(TransactionService::class);
            if ($oldMeter) {
                $transactions->updateMeterBalance($oldMeter);
            } elseif ($oldSubscriber) {
                $transactions->updateSubscriberBalance($oldSubscriber);
            }
            if ($invoice->meter) {
                $transactions->updateMeterBalance($invoice->meter);
            } else {
                $transactions->updateSubscriberBalance($invoice->subscriber);
            }
            $paymentService = app(PaymentService::class);
            $paymentService->recalculateInvoices($oldSubscriber, $oldMeterId);
            if ($oldSubscriber->id !== $invoice->subscriber_id || (int) ($oldMeterId ?? 0) !== (int) ($invoice->meter_id ?? 0)) {
                $paymentService->recalculateInvoices($invoice->subscriber, $invoice->meter_id);
            }
        });

        return response()->json(['message' => 'تم تحديث الفاتورة بنجاح.', 'invoice' => $this->decorate($invoice->fresh(['subscriber.square', 'meter', 'items', 'readings', 'payments']))]);
    }

    public function destroy(int $id)
    {
        $this->authorizeApi(Invoice::class, 'delete');
        $invoice = Invoice::findOrFail($id);
        DB::transaction(fn () => $invoice->delete());

        return response()->json(['message' => 'تم حذف الفاتورة وإعادة توزيع الدفعات المرتبطة بها.']);
    }

    public function bulkDelete(Request $request)
    {
        $this->authorizeApi(Invoice::class, 'deleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:invoices,id']]);
        $count = 0;
        DB::transaction(function () use ($data, &$count) {
            foreach (Invoice::whereIn('id', $data['ids'])->lockForUpdate()->get() as $invoice) {
                $invoice->delete();
                $count++;
            }
        });

        return response()->json(['deleted_count' => $count]);
    }

    public function restore(int $id)
    {
        $this->authorizeApi(Invoice::class, 'restore');
        $invoice = Invoice::withTrashed()->findOrFail($id);
        if (! $invoice->trashed()) {
            return response()->json(['message' => 'الفاتورة ليست محذوفة.'], 409);
        }
        $invoice->restore();

        return response()->json(['message' => 'تمت استعادة الفاتورة.', 'invoice' => $this->decorate($invoice->fresh(['subscriber', 'meter']))]);
    }

    public function forceDestroy(int $id)
    {
        $this->authorizeApi(Invoice::class, 'forceDelete');
        $invoice = Invoice::withTrashed()->findOrFail($id);
        DB::transaction(function () use ($invoice) {
            if (! $invoice->trashed()) {
                $invoice->delete();
            }
            InvoiceItem::where('invoice_id', $invoice->id)->delete();
            $invoice->forceDelete();
        });

        return response()->json(['message' => 'تم حذف الفاتورة نهائياً.']);
    }

    public function bulkRestore(Request $request)
    {
        $this->authorizeApi(Invoice::class, 'restoreAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct']]);
        $restored = 0;
        DB::transaction(function () use ($data, &$restored) {
            foreach (Invoice::onlyTrashed()->whereIn('id', $data['ids'])->lockForUpdate()->get() as $invoice) {
                $invoice->restore();
                $restored++;
            }
        });

        return response()->json(['restored_count' => $restored]);
    }

    public function bulkForceDelete(Request $request)
    {
        $this->authorizeApi(Invoice::class, 'forceDeleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct']]);
        $deleted = 0;
        DB::transaction(function () use ($data, &$deleted) {
            foreach (Invoice::withTrashed()->whereIn('id', $data['ids'])->lockForUpdate()->get() as $invoice) {
                if (! $invoice->trashed()) {
                    $invoice->delete();
                }
                InvoiceItem::where('invoice_id', $invoice->id)->delete();
                $invoice->forceDelete();
                $deleted++;
            }
        });

        return response()->json(['deleted_count' => $deleted]);
    }

    public function generateMeterInvoice(Request $request, InvoiceService $invoiceService)
    {
        $this->authorizeApi(Invoice::class, 'create');
        $data = $request->validate([
            'subscriber_id' => ['required', 'integer', 'exists:subscribers,id'],
            'meter_id' => ['required', 'integer', 'exists:meters,id'],
            'has_subscription_fee' => ['sometimes', 'boolean'],
            'subscription_installment' => ['nullable', 'numeric', 'min:0'],
            'has_tanker_fee' => ['sometimes', 'boolean'],
            'tanker_installment' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
        $meter = Meter::with('subscriber')->where('subscriber_id', $data['subscriber_id'])->findOrFail($data['meter_id']);
        $subscription = $request->boolean('has_subscription_fee')
            ? (float) ($data['subscription_installment'] ?? $meter->subscriber->getRemainingSubscriptionFee()) : null;
        $tanker = $request->boolean('has_tanker_fee')
            ? (float) ($data['tanker_installment'] ?? 0) : null;

        return $this->generateResponse(fn () => $invoiceService->generateInvoice(
            subscriber: $meter->subscriber,
            meter: $meter,
            subscriptionInstallment: $subscription,
            notes: $data['notes'] ?? null,
            tankerInstallment: $tanker,
        ));
    }

    public function generateSubscriptionInvoice(Request $request, InvoiceService $invoiceService)
    {
        $this->authorizeApi(Invoice::class, 'create');
        $data = $request->validate([
            'subscriber_id' => ['required', 'integer', 'exists:subscribers,id'],
            'meter_id' => ['nullable', 'integer', 'exists:meters,id'],
            'subscription_fee_id' => ['nullable', 'integer', 'exists:subscription_fees,id'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'subscription_installment' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
        if (! empty($data['subscription_fee_id'])) {
            $fee = SubscriptionFee::with(['subscriber', 'meter'])->findOrFail($data['subscription_fee_id']);
            if ($fee->subscriber_id !== (int) $data['subscriber_id'] || $fee->status !== 'uninvoiced') {
                return response()->json(['message' => 'رسوم الاشتراك المحددة غير متاحة لهذا المشترك.'], 422);
            }

            return $this->generateResponse(fn () => $invoiceService->generateInvoiceForSubscriptionFee($fee, $data['notes'] ?? null));
        }
        $subscriber = Subscriber::findOrFail($data['subscriber_id']);
        $meter = ! empty($data['meter_id']) ? Meter::where('subscriber_id', $subscriber->id)->findOrFail($data['meter_id']) : null;
        $amount = (float) ($data['subscription_installment'] ?? $data['amount'] ?? $subscriber->getRemainingSubscriptionFee());

        return $this->generateResponse(fn () => $invoiceService->generateInvoice(
            subscriber: $subscriber,
            meter: $meter,
            subscriptionInstallment: $amount,
            notes: $data['notes'] ?? null,
            includeReadings: false,
            invoiceType: 'subscription',
        ));
    }

    public function generateTankerInvoice(Request $request, InvoiceService $invoiceService)
    {
        $this->authorizeApi(Invoice::class, 'create');
        $data = $request->validate([
            'subscriber_id' => ['required', 'integer', 'exists:subscribers,id'],
            'service_request_id' => ['nullable', 'integer', 'exists:service_requests,id'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'tanker_installment' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
        if (! empty($data['service_request_id'])) {
            $serviceRequest = ServiceRequest::with('subscriber')->findOrFail($data['service_request_id']);
            if ($serviceRequest->subscriber_id !== (int) $data['subscriber_id'] || $serviceRequest->status !== 'completed') {
                return response()->json(['message' => 'طلب الوايت المحدد غير جاهز للفوترة.'], 422);
            }

            return $this->generateResponse(fn () => $invoiceService->generateInvoiceForTankerFee($serviceRequest, $data['notes'] ?? null));
        }
        $subscriber = Subscriber::findOrFail($data['subscriber_id']);
        $amount = (float) ($data['tanker_installment'] ?? $data['amount'] ?? 0);

        return $this->generateResponse(fn () => $invoiceService->generateInvoice(
            subscriber: $subscriber,
            meter: null,
            notes: $data['notes'] ?? null,
            tankerInstallment: $amount,
            includeReadings: false,
            invoiceType: 'tanker',
        ));
    }

    public function pay(Request $request, int $id, PaymentService $paymentService)
    {
        $this->authorizeApi(Payment::class, 'create');
        $invoice = Invoice::with('subscriber')->findOrFail($id);

        return $this->collectForInvoice($request, $invoice, $paymentService);
    }

    public function payFromPayload(Request $request, PaymentService $paymentService)
    {
        $data = $request->validate(['invoice_id' => ['required', 'integer', 'exists:invoices,id']]);
        $this->authorizeApi(Payment::class, 'create');
        $invoice = Invoice::with('subscriber')->findOrFail($data['invoice_id']);

        return $this->collectForInvoice($request, $invoice, $paymentService);
    }

    public function recalculate(int $id, PaymentService $paymentService)
    {
        $this->authorizeApi(Invoice::class, 'update');
        $invoice = Invoice::with(['subscriber', 'meter', 'readings'])->findOrFail($id);
        $readings = $invoice->readings;
        if ($readings->isEmpty() && $invoice->meter_id && $invoice->billing_period_start && $invoice->billing_period_end) {
            $readings = Reading::where('meter_id', $invoice->meter_id)
                ->whereBetween('reading_date', [$invoice->billing_period_start, $invoice->billing_period_end])->get();
        }
        if ($invoice->invoice_type === 'meter' && $readings->isNotEmpty()) {
            $invoice->consumption_fee = (float) $readings->sum('consumption_units') * Tariff::getActivePrice();
        }
        $older = Invoice::where('subscriber_id', $invoice->subscriber_id)
            ->where('invoice_type', $invoice->invoice_type)->where('id', '<', $invoice->id)
            ->when($invoice->meter_id, fn ($q) => $q->where('meter_id', $invoice->meter_id), fn ($q) => $q->whereNull('meter_id'))
            ->get();
        $invoice->previous_balance = $older->sum(fn (Invoice $item) => $item->getRemainingAmount());
        $invoice->total_amount = (float) $invoice->consumption_fee + (float) $invoice->subscription_installment + (float) $invoice->tanker_charges;
        DB::transaction(function () use ($invoice, $paymentService) {
            $invoice->save();
            $this->syncItems($invoice);
            $transaction = Transaction::where('reference_type', Invoice::class)->where('reference_id', $invoice->id)->first();
            if ($transaction) {
                $transaction->update(['amount' => -(float) $invoice->total_amount]);
            }
            $paymentService->recalculateInvoices($invoice->subscriber, $invoice->meter_id);
        });

        return response()->json(['message' => 'تمت إعادة احتساب الفاتورة.', 'invoice' => $this->decorate($invoice->fresh(['subscriber', 'meter', 'items', 'readings']))]);
    }

    public function bulkRecalculate(Request $request, PaymentService $paymentService)
    {
        $this->authorizeApi(Invoice::class, 'update');
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:invoices,id'],
        ]);
        $query = Invoice::query();
        if (! empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        }
        $processed = 0;
        $errors = [];
        foreach ($query->orderBy('id')->get() as $invoice) {
            try {
                $this->recalculate($invoice->id, $paymentService);
                $processed++;
            } catch (\Throwable $exception) {
                $errors[] = ['invoice_id' => $invoice->id, 'message' => $exception->getMessage()];
            }
        }

        return response()->json(['recalculated_count' => $processed, 'errors' => $errors]);
    }

    public function downloadPdf(int $id)
    {
        $this->authorizeApi(Invoice::class, 'view');
        $invoice = Invoice::with(['subscriber.square', 'meter', 'items', 'readings'])->findOrFail($id);

        return Pdf::view('pdf.invoice', ['invoice' => $invoice])
            ->withBrowsershot(fn ($browsershot) => $browsershot->noSandbox())
            ->margins(0, 0, 0, 0, Unit::Millimeter)
            ->format('A5')->landscape()
            ->name('invoice-'.$invoice->bill_number.'.pdf')->download();
    }

    public function whatsappLink(int $id, WhatsAppService $whatsAppService)
    {
        $this->authorizeApi(Invoice::class, 'view');
        $invoice = Invoice::with(['subscriber.square', 'meter', 'readings'])->findOrFail($id);
        $url = $whatsAppService->getInvoiceMessageUrl($invoice);
        if (! $url) {
            return response()->json(['message' => 'لا يوجد رقم هاتف صالح للمشترك أو المستأجر.'], 422);
        }
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        return response()->json(['url' => $url, 'message' => $query['text'] ?? null]);
    }

    public function sendWhatsApp(int $id)
    {
        $this->authorizeApi(Invoice::class, 'view');
        $this->authorizeApi(Payment::class, 'create');
        $invoice = Invoice::with(['subscriber', 'meter'])->findOrFail($id);
        SendWhatsAppInvoiceJob::dispatch($invoice);

        return response()->json(['message' => 'تمت إضافة إرسال الفاتورة إلى قائمة المهام.', 'queued' => true], 202);
    }

    private function collectForInvoice(Request $request, Invoice $invoice, PaymentService $paymentService)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'in:cash,bank_transfer,check'],
            'payment_date' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'sync_uuid' => ['nullable', 'uuid'],
        ]);
        try {
            $payment = $paymentService->collectPayment(
                $invoice->subscriber,
                (float) $data['amount'],
                $invoice->id,
                $data['payment_method'],
                $data['reference_number'] ?? null,
                $data['notes'] ?? null,
                $data['reason'] ?? null,
                $request->user()->id,
                $data['payment_date'] ?? null,
                null,
                $data['sync_uuid'] ?? null,
            );
            $invoice->refresh();

            // يقرأ تطبيق الهاتف إجمالي الفاتورة والمسدد من كائن `invoice`
            // لعرض الإيصال بعد التحصيل (payments_remote_datasource._parseOutcome).
            return response()->json([
                'message' => 'تم تسجيل الدفعة بنجاح.',
                'payment' => $payment->load(['subscriber.square', 'meter', 'invoice', 'user']),
                'invoice' => $this->decorate($invoice),
                'invoice_status' => $invoice->status,
                'receipt_data' => $this->receiptData($payment, $invoice, $request),
            ], 201);
        } catch (HttpExceptionInterface $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (\Throwable $exception) {
            Log::error('Invoice payment failed', ['invoice_id' => $invoice->id, 'error' => $exception->getMessage()]);

            return response()->json(['message' => 'حدث خطأ أثناء معالجة الدفع.'], 500);
        }
    }

    private function generateResponse(callable $callback)
    {
        try {
            $invoice = $callback();

            return response()->json([
                'message' => 'تم إصدار الفاتورة بنجاح.',
                'invoice' => $this->decorate($invoice->load(['subscriber.square', 'meter', 'items', 'readings'])),
                'pdf_url' => url('/api/invoices/'.$invoice->id.'/pdf'),
            ], 201);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function decorate(Invoice $invoice): array
    {
        $data = $invoice->toArray();
        $data['true_remaining_amount'] = $invoice->getTrueRemainingAmount();
        $data['remaining_amount'] = $invoice->getRemainingAmount();
        $data['is_latest_invoice'] = $invoice->isLatestInvoice();

        return $data;
    }

    private function syncItems(Invoice $invoice): void
    {
        $components = [
            'consumption' => [(float) $invoice->consumption_fee, 'استهلاك مياه'],
            'subscription' => [(float) $invoice->subscription_installment, 'رسوم اشتراك'],
            'tanker' => [(float) $invoice->tanker_charges, 'رسوم خدمة وايت ماء'],
            'previous_balance' => [(float) $invoice->previous_balance, 'متأخرات سابقة'],
        ];
        foreach ($components as $type => [$amount, $description]) {
            $item = InvoiceItem::where('invoice_id', $invoice->id)->where('type', $type)->first();
            if ($amount > 0) {
                if ($item) {
                    $item->update(['description' => $description, 'amount' => $amount]);
                } else {
                    InvoiceItem::create(['invoice_id' => $invoice->id, 'type' => $type, 'description' => $description, 'amount' => $amount]);
                }
            } elseif ($item) {
                $item->delete();
            }
        }
    }

    private function receiptData(Payment $payment, Invoice $invoice, Request $request): array
    {
        $subscriber = $invoice->subscriber->loadMissing('meters');
        $invoiceRemaining = max(0, $invoice->getTrueRemainingAmount());
        $numberWords = class_exists(\NumberFormatter::class)
            ? (new \NumberFormatter('ar', \NumberFormatter::SPELLOUT))->format((float) $payment->amount)
            : null;

        return [
            'receipt_number' => $payment->receipt_number,
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'amount_in_words' => $numberWords ? $numberWords.' ريال يمني لا غير' : null,
            'date' => optional($payment->payment_date)->format('Y-m-d'),
            'payment_method' => $payment->payment_method,
            'reason' => $payment->reason,
            'subscriber_name' => $subscriber->name,
            'subscriber_phone' => $payment->meter?->tenant_phone ?: $subscriber->phone,
            'invoice_remaining' => number_format($invoiceRemaining, 2, '.', ''),
            'outstanding_balance' => number_format($subscriber->getOutstandingBalance() + $subscriber->meters->sum(fn ($meter) => $meter->getOutstandingBalance()), 2, '.', ''),
            'credit_balance' => number_format(max(0, (float) $subscriber->balance) + $subscriber->meters->sum(fn ($meter) => max(0, (float) $meter->balance)), 2, '.', ''),
            'collector' => $request->user()?->name,
            'pdf_url' => url('/api/payments/'.$payment->id.'/pdf'),
            // نص رسالة واتساب للفاتورة — يستخدمه التطبيق في شاشة الإيصال.
            'message_template' => app(WhatsAppService::class)->invoiceMessage($invoice),
        ];
    }
}
