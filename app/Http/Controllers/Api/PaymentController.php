<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SendWhatsAppPaymentJob;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\WhatsAppService;
use App\Support\ArabicNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelPdf\Enums\Unit;
use Spatie\LaravelPdf\Facades\Pdf;

class PaymentController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi(Payment::class, 'viewAny');
        $query = Payment::with([
            'subscriber:id,name,phone,square_id', 'subscriber.square:id,name,code', 'subscriber.meters:id,subscriber_id,path_number',
            'meter:id,unique_code,path_number',
            'invoice:id,bill_number,status,total_amount,amount_paid,subscriber_id,deleted_at', 'user:id,name',
            'accountTransfer:id,payment_id,from_subscriber_id,to_subscriber_id,amount,created_at',
        ])->withSum('refunds as refunded_amount', 'amount')->latest('payment_date')->latest('id');

        if ($request->filled('cash_session_id')) {
            $query->where('cash_session_id', $request->integer('cash_session_id'));
        }
        if ($request->query('voided') === 'only') {
            $query->whereNotNull('voided_at');
        } elseif ($request->query('voided') !== 'all') {
            $query->whereNull('voided_at');
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->query('payment_method'));
        }
        if ($request->filled('subscriber_id')) {
            $query->where('subscriber_id', $request->integer('subscriber_id'));
        }
        // يرسلها تطبيق الهاتف لعرض سندات فاتورة معيّنة (تفاصيل الفاتورة).
        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->integer('invoice_id'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('payment_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('payment_date', '<=', $request->query('to_date'));
        }
        if ($search = trim((string) $request->query('q', $request->query('search', '')))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('receipt_number', 'like', "%{$search}%")
                    ->orWhereHas('subscriber.square', fn ($square) => $square->where('code', 'like', "%{$search}%"))
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('subscriber', fn ($q) => $q->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"))
                    ->orWhereHas('meter', fn ($q) => $q->where('unique_code', 'like', "%{$search}%"));
            });
        }

        $this->applySorting($query, $request, self::LIST_SORTS['payments']);

        return $query->paginate($this->perPage($request))->through(function (Payment $payment) {
            $payment->setAttribute('receipt_data', $this->receiptData($payment));
            // The exact debt projection needs these columns; keep the existing list response contract.
            $payment->invoice?->makeHidden(['amount_paid', 'subscriber_id', 'deleted_at']);

            return $payment;
        })->appends($request->query());
    }

    public function show(int $id)
    {
        $this->authorizeApi(Payment::class, 'view');
        $payment = Payment::with([
            'subscriber.square', 'meter', 'invoice', 'user',
            'accountTransfer:id,payment_id,from_subscriber_id,to_subscriber_id,amount,created_at',
        ])->withSum('refunds as refunded_amount', 'amount')->findOrFail($id);

        $payment->setAttribute('allocation_snapshot', app(\App\Services\PaymentAllocationReadService::class)->forPayment($payment));
        return response()->json(['payment' => $payment, 'receipt_data' => $this->receiptData($payment)]);
    }

    public function store(Request $request, PaymentService $paymentService)
    {
        $this->authorizeApi(Payment::class, 'create');
        $data = $request->validate([
            'subscriber_id' => ['required', 'integer', 'exists:subscribers,id'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'meter_id' => ['nullable', 'integer', 'exists:meters,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['nullable', 'date'],
            'payment_method' => ['required', 'in:cash,bank_transfer,check'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'sync_uuid' => ['nullable', 'uuid'],
            'cash_session_id' => ['nullable', 'integer', 'min:1'],
            'deferred_sync' => ['sometimes', 'boolean'],
        ]);
        $subscriber = Subscriber::findOrFail($data['subscriber_id']);
        if (! empty($data['invoice_id']) && ! Invoice::where('id', $data['invoice_id'])->where('subscriber_id', $subscriber->id)->exists()) {
            return response()->json(['message' => 'الفاتورة المحددة لا تتبع هذا المشترك.'], 422);
        }
        if (! empty($data['meter_id']) && ! Meter::where('id', $data['meter_id'])->where('subscriber_id', $subscriber->id)->exists()) {
            return response()->json(['message' => 'العداد المحدد لا يتبع هذا المشترك.'], 422);
        }
        if (! empty($data['invoice_id']) && ! empty($data['meter_id'])) {
            $invoice = Invoice::findOrFail($data['invoice_id']);
            if ((int) $invoice->meter_id !== (int) $data['meter_id']) {
                return response()->json(['message' => 'العداد المحدد لا يطابق حساب الفاتورة.'], 422);
            }
        }

        $entry = app(\App\Services\ProspectiveCashReceiptEntry::class);
        $payment = $entry->enabled()
            ? $entry->collect($request->user(), $subscriber, $data)
            : $paymentService->collectPayment(
            $subscriber,
            $data['amount'],
            $data['invoice_id'] ?? null,
            $data['payment_method'],
            $data['reference_number'] ?? null,
            $data['notes'] ?? null,
            $data['reason'] ?? null,
            $request->user()->id,
            $data['payment_date'] ?? null,
            $data['meter_id'] ?? null,
            $data['sync_uuid'] ?? null,
            $data['cash_session_id'] ?? null,
            (bool) ($data['deferred_sync'] ?? false),
        );
        $payment->load(['subscriber.square', 'meter', 'invoice', 'user']);

        return response()->json(['message' => 'تم تسجيل سند القبض بنجاح.', 'payment' => $payment, 'receipt_data' => $this->receiptData($payment)], 201);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeApi(Payment::class, 'update');
        $payment = Payment::with('subscriber')->findOrFail($id);
        $data = $request->validate([
            'invoice_id' => ['sometimes', 'nullable', 'integer', 'exists:invoices,id'],
            'meter_id' => ['sometimes', 'nullable', 'integer', 'exists:meters,id'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'payment_date' => ['sometimes', 'required', 'date'],
            'payment_method' => ['sometimes', 'required', 'in:cash,bank_transfer,check'],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);
        $payment->fill($data);
        $payment->assertFinancialFieldsUnchanged();
        DB::transaction(fn () => $payment->save());
        $payment->refresh()->load(['subscriber.square', 'meter', 'invoice', 'user']);

        return response()->json(['message' => 'تم تحديث سند القبض بنجاح.', 'payment' => $payment, 'receipt_data' => $this->receiptData($payment)]);
    }

    /**
     * إلغاء سند قبض (لا حذف). يتطلب سبباً وصلاحية `void:Payment`.
     * مرجع: خطة الإصدار 2.0 — الفصل 5.3.
     */
    /**
     * طلب إلغاء سند قبض.
     *
     * تحت الحد المالي: يُلغى فوراً ويُعاد 200 كما في v1.
     * عند/فوق الحد: يُسجَّل طلب معلّق ويُعاد 202 مع `pending_approval: true`
     * حتى يعتمده مشرف. لا يُحذف السند في أي حال.
     */
    public function void(Request $request, int $id, PaymentService $paymentService)
    {
        $this->authorizeApi(Payment::class, 'void');

        $candidate = $this->prospectiveVoidApi();
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'operation_uuid' => ['nullable', 'uuid'],
            'intent_uuid' => ['nullable', 'uuid'],
        ]);

        $intent = isset($data['intent_uuid']) ? strtolower($data['intent_uuid']) : null;
        abort_if(! $candidate && ($intent !== null || isset($data['operation_uuid'])), 409,
            'مسار الإلغاء المالي المؤكد غير متاح.');
        abort_if($candidate && ($intent === null && ! isset($data['operation_uuid'])), 409,
            'يجب تأكيد طلب الإلغاء عبر اتصال مباشر ومعرّف عملية صالح.');
        $payment = Payment::findOrFail($id);
        $result = $candidate
            ? $this->voidCandidateCall(fn () => app(\App\Services\GatedReceiptVoidWriter::class)->request(
                $request->user(), $id, (string) $data['reason'], now()->toDateTimeImmutable(),
                isset($data['operation_uuid']) ? strtolower($data['operation_uuid']) : null, $intent))
            : $paymentService->requestVoid(
                $payment, (string) $data['reason'], $request->user()->id,
                $request->header('X-Client-Source') === 'mobile' ? 'mobile' : 'api',
            );

        /** @var Payment $payment */
        $payment = $result['payment'];
        $payment->load(['subscriber.square', 'meter', 'invoice', 'user']);

        if ($result['pending']) {
            return response()->json([
                'message' => 'السند يتجاوز حد الإلغاء المسموح؛ أُرسل طلب إلغاء لاعتماد المشرف.',
                'pending_approval' => true,
                ...($candidate ? ['request_id' => $result['request_id']] : []),
                ...($intent !== null ? ['intent_uuid' => $result['intent_uuid']] : []),
                'payment' => $payment,
                'receipt_data' => $this->receiptData($payment),
            ], 202);
        }

        return response()->json([
            'message' => 'تم إلغاء سند القبض وإعادة احتساب الأرصدة.',
            'pending_approval' => false,
            ...($intent !== null ? ['intent_uuid' => $result['intent_uuid']] : []),
            'payment' => $payment,
            'receipt_data' => $this->receiptData($payment),
        ]);
    }

    /** طلبات الإلغاء المعلّقة — للمشرف فقط. */
    public function voidRequests(Request $request)
    {
        $this->authorizeVoidApprover($request->user());

        $query = Payment::query()
            ->voidRequested()
            ->with(['subscriber:id,name,phone,square_id', 'subscriber.square:id,name,code', 'subscriber.meters:id,subscriber_id,path_number', 'meter:id,unique_code,path_number', 'voidRequester:id,name'])
            ->latest('void_requested_at');
        $candidate = $this->prospectiveVoidApi();
        if ($candidate) {
            $query->addSelect(['request_id' => DB::table('audit_events')->select('id')
                ->whereColumn('entity_id', 'payments.id')->where('entity_type', Payment::class)
                ->where('event', 'financial.void_request_gated')->orderByDesc('id')->limit(1)]);
        }
        $this->applySorting($query, $request, self::LIST_SORTS['void_requests']);
        $payments = $query->paginate($this->perPage($request))->appends($request->query());

        return $payments->through(fn (Payment $payment) => [
            'id' => $payment->id,
            ...($candidate ? ['request_id' => $payment->request_id === null ? null : (int) $payment->request_id] : []),
            'created_at' => $payment->created_at?->toISOString(),
            'square_id' => $payment->subscriber?->square_id,
            'square_name' => $payment->subscriber?->square?->name,
            'square_code' => $payment->subscriber?->square?->code,
            'path_numbers' => ($payment->meter_id === null
                ? ($payment->subscriber?->meters ?? collect())->pluck('path_number')
                : collect([$payment->meter?->path_number]))->filter(fn ($path) => $path !== null)->map(fn ($path) => (int) $path)->unique()->sort()->values()->all(),
            'receipt_number' => $payment->receipt_number,
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'subscriber' => $payment->subscriber?->name,
            'reason' => $payment->void_request_reason,
            'requested_by' => $payment->voidRequester?->name,
            'requested_at' => optional($payment->void_requested_at)->toIso8601String(),
            'payment_date' => optional($payment->payment_date)->toDateString(),
        ]);
    }

    /** قرار المشرف: اعتماد الإلغاء أو رفضه (مع ملاحظات). */
    public function voidDecision(Request $request, int $id, PaymentService $paymentService)
    {
        $this->authorizeVoidApprover($request->user());

        $candidate = $this->prospectiveVoidApi();
        abort_if(! $candidate && ($request->has('request_id') || $request->has('operation_uuid')), 409,
            'مسار القرار المالي المرتبط بالطلب غير متاح. لا يمكن تنفيذه عبر المسار السابق.');
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...($candidate ? [
                'request_id' => ['required', 'integer', 'min:1'],
                'operation_uuid' => [\Illuminate\Validation\Rule::requiredIf($request->boolean('approve')), 'nullable', 'uuid'],
            ] : []),
        ]);

        $payment = Payment::findOrFail($id);
        $payment = $candidate
            ? $this->voidCandidateCall(fn () => app(\App\Services\GatedReceiptVoidWriter::class)->decide(
                $request->user(), $id, (bool) $data['approve'], $data['notes'] ?? null,
                now()->toDateTimeImmutable(),
                isset($data['operation_uuid']) ? strtolower($data['operation_uuid']) : null,
                (int) $data['request_id']))['payment']
            : $paymentService->decideVoidRequest(
                $payment, (bool) $data['approve'], $request->user(), $data['notes'] ?? null,
                $request->header('X-Client-Source') === 'mobile' ? 'mobile' : 'api',
            );

        $payment->load(['subscriber.square', 'meter', 'invoice', 'user']);

        return response()->json([
            'message' => $data['approve']
                ? 'تم اعتماد إلغاء السند وإعادة احتساب الأرصدة.'
                : 'تم رفض طلب الإلغاء وبقي السند صالحاً.',
            ...($candidate ? ['request_id' => (int) $data['request_id'],
                'operation_uuid' => isset($data['operation_uuid']) ? strtolower($data['operation_uuid']) : null] : []),
            'payment' => $payment,
            'receipt_data' => $this->receiptData($payment),
        ]);
    }

    public function recordRefund(Request $request, int $id)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'disbursement_reference' => ['required', 'string', 'min:3', 'max:150'],
            'method' => ['required', 'in:treasury_cash,bank_transfer'],
            'disbursed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'disbursement_confirmed' => ['required', 'accepted'],
            'treasury_account_id' => ['nullable', 'integer', 'min:1'],
            'operation_uuid' => ['nullable', 'uuid'],
        ]);
        return response()->json(app(\App\Services\ProspectiveRefundEntry::class)->record(
            $request->user(), Payment::findOrFail($id), $data));
    }

    public function transferAccount(Request $request, int $id)
    {
        $data = $request->validate([
            'to_subscriber_id' => ['required', 'integer', 'exists:subscribers,id'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'no_new_cash_movement' => ['required', 'accepted'],
        ]);
        $transfer = app(\App\Services\PaymentAccountTransferService::class)->transfer(
            Payment::findOrFail($id), (int) $data['to_subscriber_id'], $request->user(), $data['reason'], $data['evidence']
        );
        return response()->json(['transfer' => $transfer]);
    }

    public function destroy(int $id)
    {
        $this->authorizeApi(Payment::class, 'delete');
        Payment::findOrFail($id)->delete();

        return response()->json(['message' => 'تم حذف سند القبض وإعادة توزيع الدفعات.']);
    }

    public function bulkDelete(Request $request)
    {
        $this->authorizeApi(Payment::class, 'deleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:payments,id']]);
        $deleted = 0;
        DB::transaction(function () use ($data, &$deleted) {
            foreach (Payment::whereIn('id', $data['ids'])->lockForUpdate()->get() as $payment) {
                $payment->delete();
                $deleted++;
            }
        });

        return response()->json(['deleted_count' => $deleted]);
    }

    public function downloadPdf(int $id)
    {
        $this->authorizeApi(Payment::class, 'view');
        $payment = Payment::with(['subscriber.square', 'meter', 'invoice', 'user'])->findOrFail($id);

        return Pdf::view('pdf.receipt', ['payment' => $payment])
            ->withBrowsershot(fn ($browsershot) => $browsershot->noSandbox())
            ->margins(0, 0, 0, 0, Unit::Millimeter)
            ->format('A5')->landscape()
            ->name('receipt-'.$payment->receipt_number.'.pdf')->download();
    }

    public function whatsappLink(int $id, WhatsAppService $whatsAppService)
    {
        $this->authorizeApi(Payment::class, 'view');
        $payment = Payment::with(['subscriber', 'meter', 'invoice'])->findOrFail($id);
        $url = $whatsAppService->getPaymentMessageUrl($payment);
        if (! $url) {
            return response()->json(['message' => 'لا يوجد رقم هاتف صالح للمشترك.'], 422);
        }
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        return response()->json(['url' => $url, 'message' => $query['text'] ?? null]);
    }

    public function sendWhatsApp(int $id)
    {
        $this->authorizeApi(Payment::class, 'update');
        $payment = Payment::with(['subscriber', 'meter', 'invoice'])->findOrFail($id);
        SendWhatsAppPaymentJob::dispatch($payment);

        return response()->json(['message' => 'تمت إضافة إرسال سند القبض إلى قائمة المهام.', 'queued' => true], 202);
    }

    /**
     * تسجيل نتيجة إرسال واتساب على السند (خطة 2.0 — الفصل 6.2).
     *
     * التطبيق ينادي هذا المسار بعد نجاح الفتح/الإرسال، فيبقى أثر «ما وصل
     * للمشترك» على الخادم لا على الجهاز وحده. لا نُفشل واجهة المحصّل إن فشل
     * هذا التسجيل: الإرسال وقع فعلاً، والسجل المحلي (audit) يبقى شبكة أمان.
     *
     * الطرق المسموحة: `click_to_chat` (فتح واتساب بنص جاهز)، `meta_api`
     * و`local_api` (إرسال من الخادم)، و`manual` (نسخ النص يدوياً).
     */
    public function recordWhatsAppResult(Request $request, int $id)
    {
        $this->authorizeApi(Payment::class, 'view');

        $data = $request->validate([
            'via' => ['required', 'string', 'in:click_to_chat,meta_api,local_api,manual'],
        ]);

        $payment = Payment::findOrFail($id);
        $payment->forceFill([
            'whatsapp_sent_at' => now(),
            'whatsapp_sent_via' => $data['via'],
            'whatsapp_sent_by' => $request->user()?->id,
        ])->saveQuietly();

        return response()->json([
            'message' => 'تم تسجيل إرسال السند عبر واتساب.',
            'whatsapp_sent_at' => optional($payment->whatsapp_sent_at)->toIso8601String(),
            'whatsapp_sent_via' => $payment->whatsapp_sent_via,
        ]);
    }

    private function receiptData(Payment $payment): array
    {
        $payment->loadMissing(['subscriber.meters', 'meter', 'invoice', 'user']);
        $amount = (float) $payment->amount;
        $amountInWords = ArabicNumber::spellout($amount);
        $invoiceRemaining = $payment->invoice ? $payment->invoice->getTrueRemainingAmount() : null;
        $subscriber = $payment->subscriber;
        $debit = $subscriber->getAccountDebt();
        $credit = $subscriber->getAccountCredit();

        return [
            'receipt_number' => $payment->receipt_number,
            'amount' => number_format($amount, 2, '.', ''),
            'amount_in_words' => $amountInWords,
            'date' => optional($payment->payment_date)->format('Y-m-d'),
            'payment_method' => $payment->payment_method,
            'reference_number' => $payment->reference_number,
            'reason' => $payment->reason,
            'subscriber_name' => $subscriber->name,
            'subscriber_phone' => $subscriber->phone,
            'square' => $subscriber->square?->name,
            'meter' => $payment->meter?->unique_code,
            'invoice_number' => $payment->invoice?->bill_number,
            'invoice_remaining' => $invoiceRemaining === null ? null : number_format((float) $invoiceRemaining, 2, '.', ''),
            'outstanding_balance' => number_format((float) $debit, 2, '.', ''),
            'credit_balance' => number_format((float) $credit, 2, '.', ''),
            'collector' => $payment->user?->name,
            'voided' => $payment->voided_at !== null,
            'void_reason' => $payment->void_reason,
            'cash_session_id' => $payment->cash_session_id,
            // نص رسالة واتساب المُعدّ من قالب الإعدادات — يستخدمه التطبيق في شاشة الإيصال.
            'message_template' => app(WhatsAppService::class)->paymentMessage($payment),
            'pdf_url' => url('/api/payments/'.$payment->id.'/pdf'),
        ];
    }

    /** Explicitly restricted candidate; never activate in a production environment. */
    private function prospectiveVoidApi(): bool
    {
        if (config('financial_candidate.void_api') !== true) return false;
        abort_unless(app()->environment(['testing', 'staging']), 503, 'مسار الاختبار المالي غير متاح في الإنتاج.');
        return true;
    }

    private function voidCandidateCall(callable $action): array
    {
        try {
            return $action();
        } catch (\DomainException $error) {
            // Do not fall back to the legacy writer on a gate or identity failure.
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(
                'تعذر تنفيذ العملية المالية: '.$error->getMessage(), $error);
        }
    }

    /** اعتماد الإلغاء يحتاج صلاحية مشرف صريحة. */
    private function authorizeVoidApprover(User $user): void
    {
        if ($this->isAdminUser($user)
            || $user->can('payments.void.approve')
            || $user->can('Approve:Payment')
            || $user->can('approve_payment')) {
            return;
        }

        abort(403, 'اعتماد إلغاء السند يتطلب صلاحية مشرف.');
    }
}
