<?php

namespace App\Http\Controllers\Api;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Square;
use App\Models\Subscriber;
use App\Models\Transaction;
use App\Services\PaymentService;
use App\Support\AccountMoney;
use App\Support\DecimalDomain;
use App\Support\MoneyBoundary;
use App\Support\SignedMinor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriberController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi(Subscriber::class, 'viewAny');
        $query = Subscriber::query()
            ->with(['square:id,name,code', 'meters:id,subscriber_id,unique_code,path_number,status,balance,removed_at', 'currentMeter:id,subscriber_id,unique_code,path_number,status,removed_at'])
            ->withCount(['currentMeter as meters_count'])
            ->orderBy('name');

        if ($search = trim((string) $request->query('q', $request->query('search', '')))) {
            $normalized = $this->normalizeDigits($search);
            if ($path = $this->parseCompoundSearch($normalized)) {
                [$squareId, $pathNumber] = $path;
                $query->where('square_id', $squareId)->whereHas('currentMeter', fn ($q) => $q->where('path_number', $pathNumber));
            } else {
                $query->where(function ($builder) use ($search, $normalized) {
                    $builder->where('name', 'like', "%{$search}%")
                        ->orWhereHas('square', fn ($square) => $square->where('code', 'like', "%{$search}%"))
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhereHas('currentMeter', function ($meterQuery) use ($search, $normalized) {
                            $meterQuery->where('unique_code', 'like', "%{$search}%")
        ;
                            if (ctype_digit($normalized)) {
                                $meterQuery->orWhere('path_number', (int) $normalized);
                            }
                        });
                });
            }
        }
        if ($request->filled('square_id')) {
            $query->where('square_id', $request->integer('square_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $this->applySorting($query, $request, self::LIST_SORTS['subscribers']);
        $subscribers = $query->paginate($this->perPage($request))->through(fn (Subscriber $subscriber) => $this->subscriberListItem($subscriber));

        return $subscribers->appends($request->query());
    }

    public function store(Request $request)
    {
        $this->authorizeApi(Subscriber::class, 'create');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'square_id' => ['required', 'integer', 'exists:squares,id'],
            'status' => ['sometimes', 'in:active,inactive,suspended'],
            'subscription_start_date' => ['nullable', 'date'],
            'address' => ['nullable', 'string'],
        ]);
        $data['status'] ??= 'active';
        $subscriber = Subscriber::create($data)->load('square');

        return response()->json(['message' => 'تم إضافة المشترك بنجاح.', 'subscriber' => $subscriber], 201);
    }

    public function show(int $id)
    {
        $this->authorizeApi(Subscriber::class, 'view');
        $subscriber = Subscriber::with([
            'square:id,name,code,description',
            'meters' => fn ($q) => $q->withCount('readings')->orderBy('id'),
            'currentMeter:id,subscriber_id,unique_code,path_number,status,removed_at',
            'invoices' => fn ($q) => $q->with(['meter:id,unique_code,path_number'])->latest('bill_date')->latest('id'),
            'payments' => fn ($q) => $q->with(['meter:id,unique_code', 'user:id,name', 'accountTransfer:id,payment_id,to_subscriber_id,amount,created_at', 'refunds:id,payment_id,amount'])->latest('payment_date')->latest('id'),
            'transactions' => fn ($q) => $q->latest(),
        ])->findOrFail($id);

        $subscriber->setAttribute('financial_summary', $this->financialSummary($subscriber));
        $subscriber->setAttribute('account_balance', $subscriber->getAccountBalance());
        $subscriber->setAttribute('incoming_account_transfers', \App\Models\PaymentAccountTransfer::where('to_subscriber_id', $subscriber->id)->orderBy('created_at')->orderBy('id')->get(['id', 'payment_id', 'to_subscriber_id', 'amount', 'created_at']));
        $subscriber->payments->each(function (Payment $payment) {
            $sum = $this->refundMinor($payment);
            $payment->setAttribute('refunded_amount', $payment->refunds->isEmpty() ? null :
                ($payment->getConnection()->getDriverName() === 'mysql' ? SignedMinor::decimal($sum) : MoneyBoundary::numericMajorOutput($sum)));
            $payment->unsetRelation('refunds');
        });
        $subscriber->meters->each(function (Meter $meter) {
            $balance = $this->meterMinor($meter);
            $meter->setAttribute('meter_credit', MoneyBoundary::numericMajorOutput(max(0, $balance)));
            $meter->setAttribute('meter_debit', MoneyBoundary::numericMajorOutput(max(0, SignedMinor::negate($balance))));
            $meter->setAttribute('last_reading', $meter->readings()->latest('id')->value('current_reading'));
        });
        $subscriber->invoices->each(fn (Invoice $invoice) => $this->decorateInvoice($invoice));

        // Keep the detail object flat; mobile's 360° mapper reads financial_summary/meters directly.
        return response()->json($subscriber);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeApi(Subscriber::class, 'update');
        $subscriber = Subscriber::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'square_id' => ['sometimes', 'required', 'integer', 'exists:squares,id'],
            'status' => ['sometimes', 'required', 'in:active,inactive,suspended'],
            'subscription_start_date' => ['sometimes', 'nullable', 'date'],
            'address' => ['sometimes', 'nullable', 'string'],
        ]);
        $subscriber->update($data);

        return response()->json(['message' => 'تم تحديث المشترك بنجاح.', 'subscriber' => $subscriber->fresh('square')]);
    }

    public function destroy(int $id)
    {
        $this->authorizeApi(Subscriber::class, 'delete');
        $subscriber = Subscriber::findOrFail($id);
        if ($this->hasHistory($subscriber)) {
            return response()->json([
                'message' => 'لا يمكن حذف مشترك لديه عدادات أو فواتير أو سندات أو حركات مالية مرتبطة.',
            ], 409);
        }
        $subscriber->delete();

        return response()->json(['message' => 'تم حذف المشترك بنجاح.']);
    }

    public function bulkDelete(Request $request)
    {
        $this->authorizeApi(Subscriber::class, 'deleteAny');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:subscribers,id']]);
        $deleted = 0;
        $skipped = [];
        DB::transaction(function () use ($data, &$deleted, &$skipped) {
            foreach (Subscriber::whereIn('id', $data['ids'])->lockForUpdate()->get() as $subscriber) {
                if ($this->hasHistory($subscriber)) {
                    $skipped[] = $subscriber->id;

                    continue;
                }
                $subscriber->delete();
                $deleted++;
            }
        });

        return response()->json([
            'deleted_count' => $deleted,
            'skipped_count' => count($skipped),
            'skipped_ids' => $skipped,
        ]);
    }

    private function hasHistory(Subscriber $subscriber): bool
    {
        return $subscriber->meters()->exists()
            || $subscriber->invoices()->withTrashed()->exists()
            || $subscriber->payments()->exists()
            || $subscriber->transactions()->exists();
    }

    public function summary(int $id)
    {
        $this->authorizeApi(Subscriber::class, 'view');
        $subscriber = Subscriber::with(['square:id,name,code', 'meters:id,subscriber_id,unique_code,path_number,status,balance', 'currentMeter:id,subscriber_id,unique_code,path_number,status,removed_at'])
            ->findOrFail($id);

        return response()->json([
            'subscriber' => $subscriber->only(['id', 'name', 'phone', 'address', 'status', 'square_id', 'subscription_start_date']),
            'financial_summary' => $this->financialSummary($subscriber),
        ]);
    }

    public function statement(Request $request, int $id)
    {
        $this->authorizeApi(Subscriber::class, 'view');
        $subscriber = Subscriber::findOrFail($id);
        $invoices = $subscriber->invoices()->with('meter:id,unique_code,path_number')->get()->map(function (Invoice $invoice) {
            return [
                'id' => $invoice->id,
                'type' => 'invoice',
                'date' => optional($invoice->bill_date)->format('Y-m-d'),
                'reference' => $invoice->bill_number,
                'description' => 'فاتورة '.$this->invoiceTypeLabel($invoice->invoice_type),
                'amount' => MoneyBoundary::numericMajorOutput(AccountMoney::stored($invoice, 'total_amount')),
                'previous_balance' => MoneyBoundary::numericMajorOutput(AccountMoney::stored($invoice, 'previous_balance')),
                'amount_paid' => MoneyBoundary::numericMajorOutput(AccountMoney::stored($invoice, 'amount_paid')),
                'remaining_amount' => $invoice->getRemainingAmount(),
                'true_remaining_amount' => $invoice->getTrueRemainingAmount(),
                'status' => $invoice->status,
                'meter' => $invoice->meter,
            ];
        });
        $payments = $subscriber->payments()->with(['meter:id,unique_code,path_number', 'accountTransfer', 'refunds:id,payment_id,amount'])->get()->map(function (Payment $payment) {
            return [
                'id' => $payment->id,
                'type' => 'payment',
                'date' => optional($payment->payment_date)->format('Y-m-d'),
                'reference' => $payment->receipt_number,
                'description' => 'سند قبض'.($payment->reason ? ' - '.$payment->reason : ''),
                'amount' => MoneyBoundary::numericMajorOutput(AccountMoney::stored($payment)),
                'payment_method' => $payment->payment_method,
                'voided_at' => $payment->voided_at,
                'refunded_amount' => MoneyBoundary::numericMajorOutput($this->refundMinor($payment)),
                'transferred_to_subscriber_id' => $payment->accountTransfer?->to_subscriber_id,
                'meter' => $payment->meter,
            ];
        });
        $transactions = $invoices->concat($payments)->sortByDesc('date')->values();

        return response()->json([
            'subscriber' => ['id' => $subscriber->id, 'name' => $subscriber->name, 'general_balance' => MoneyBoundary::numericMajorOutput(AccountMoney::stored($subscriber, 'balance'))],
            'transactions' => $transactions,
            'ledger' => $subscriber->transactions()->orderBy('created_at')->orderBy('id')->get(),
            'financial_summary' => $this->financialSummary($subscriber),
        ]);
    }

    public function transactions(Request $request, int $id)
    {
        $this->authorizeApi(Subscriber::class, 'view');
        $subscriber = Subscriber::findOrFail($id);
        $query = Transaction::with(['meter:id,unique_code,path_number', 'reference'])
            ->where('subscriber_id', $subscriber->id);

        if ($request->has('meter_id')) {
            if ($request->query('meter_id') === 'general') {
                $query->whereNull('meter_id')->whereNull('retired_meter_id');
            } elseif (filter_var($request->query('meter_id'), FILTER_VALIDATE_INT) !== false) {
                $query->where('meter_id', (int) $request->query('meter_id'));
            }
        }
        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        $runningBalance = 0;
        $rows = $query->orderBy('created_at')->orderBy('id')->get()->map(function (Transaction $transaction) use (&$runningBalance) {
            $amount = AccountMoney::stored($transaction);
            $runningBalance = SignedMinor::add($runningBalance, $amount);

            return [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => MoneyBoundary::numericMajorOutput($amount),
                'meter_id' => $transaction->meter_id,
                'party' => $transaction->meter_id
                    ? 'عداد رقم: '.$transaction->meter_id.($transaction->meter?->unique_code ? ' ('.$transaction->meter->unique_code.')' : '')
                    : ($transaction->retired_meter_id ? 'عداد مستبدل: '.$transaction->retired_meter_code : 'الحساب العام للمشترك'),
                'description' => $transaction->description,
                'created_at' => $transaction->created_at?->format('Y-m-d H:i'),
                'running_balance' => MoneyBoundary::numericMajorOutput($runningBalance),
            ];
        });

        return response()->json($rows->reverse()->values());
    }

    public function collectPayment(Request $request, int $id, PaymentService $paymentService)
    {
        $this->authorizeApi(Payment::class, 'create');
        $subscriber = Subscriber::findOrFail($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'meter_id' => ['nullable', 'integer', 'exists:meters,id'],
            'payment_date' => ['nullable', 'date'],
            'payment_method' => ['required', 'in:cash,bank_transfer,check'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'sync_uuid' => ['nullable', 'uuid'],
            'cash_session_id' => ['nullable', 'integer', 'min:1'],
            'deferred_sync' => ['sometimes', 'boolean'],
        ]);
        if (! empty($data['invoice_id']) && ! Invoice::where('id', $data['invoice_id'])->where('subscriber_id', $subscriber->id)->exists()) {
            return response()->json(['message' => 'الفاتورة المحددة لا تتبع هذا المشترك.'], 422);
        }
        if (! empty($data['meter_id']) && ! Meter::where('id', $data['meter_id'])->where('subscriber_id', $subscriber->id)->exists()) {
            return response()->json(['message' => 'العداد المحدد لا يتبع هذا المشترك.'], 422);
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
        );

        return response()->json(['message' => 'تم تسجيل الدفعة بنجاح.', 'payment' => $payment->load(['subscriber', 'invoice', 'meter', 'user'])], 201);
    }

    public function allocationReconciliation(int $id)
    {
        $this->authorizeApi(Subscriber::class, 'view');
        return response()->json(app(\App\Services\PaymentAllocationReadService::class)->reconcile(Subscriber::findOrFail($id)));
    }

    public function unpaidInvoicesSummary(int $id)
    {
        $this->authorizeApi(Subscriber::class, 'view');
        $subscriber = Subscriber::findOrFail($id);
        $invoices = $subscriber->invoices()
            ->with('meter:id,unique_code,path_number')
            ->withOwnDebt()
            ->orderBy('bill_date')->orderBy('id')->get();
        $invoices->each(fn (Invoice $invoice) => $this->decorateInvoice($invoice));

        // مفاتيح يقرأها تطبيق الهاتف لملء شاشة التحصيل السريع مسبقاً
        // (references_remote_datasource.SubscriberDueSummary) — تُضاف إضافياً
        // دون كسر المفاتيح القديمة (general_balance/invoices/total_remaining).
        $latestUnpaid = $invoices->sortByDesc('id')->first();
        $invoiceDebt = MoneyBoundary::numericMajorOutput(SignedMinor::sum($invoices->map(fn (Invoice $invoice) => $invoice->getRemainingMinor())));
        $subscriber->loadMissing('meters');
        $net = $subscriber->getAccountBalanceMinor();
        $outstanding = max(0, SignedMinor::negate($net));
        $credit = max(0, $net);

        return response()->json([
            'subscriber_id' => $subscriber->id,
            'general_balance' => MoneyBoundary::numericMajorOutput(AccountMoney::stored($subscriber, 'balance')),
            'invoices' => $invoices,
            'total_remaining' => $invoiceDebt,
            'invoice_id' => $latestUnpaid?->id,
            'bill_number' => $latestUnpaid?->bill_number,
            'due_amount' => $invoiceDebt,
            'outstanding_balance' => MoneyBoundary::numericMajorOutput($outstanding),
            'credit_balance' => MoneyBoundary::numericMajorOutput($credit),
            'open_invoices_count' => $invoices->count(),
        ]);
    }

    /** Backwards-compatible helpers kept for older mobile builds. */
    public function squares()
    {
        $this->authorizeApi(Square::class, 'viewAny');

        return Square::withCount('subscribers')->orderBy('name')->get();
    }

    public function storeSquare(Request $request)
    {
        $this->authorizeApi(Square::class, 'create');
        if (is_string($request->input('code'))) {
            $code = trim($request->input('code'));
            $request->merge(['code' => $code === '' ? null : $code]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:100', Rule::unique('squares', 'code')],
            'description' => ['nullable', 'string'],
        ], ['code.string' => 'أدخل معرّف المربع كنص.', 'code.unique' => 'معرّف المربع مستخدم مسبقاً. اختر معرّفاً آخر.']);

        return response()->json(['square' => Square::create($data)], 201);
    }

    private function subscriberListItem(Subscriber $subscriber): array
    {
        $summary = $this->financialSummary($subscriber);

        return [
            'id' => $subscriber->id,
            'name' => $subscriber->name,
            'phone' => $subscriber->phone,
            'address' => $subscriber->address,
            'status' => $subscriber->status,
            'square_id' => $subscriber->square_id,
            'square' => $subscriber->square,
            'meters_count' => $subscriber->meters_count,
            'current_meter_id' => $subscriber->currentMeter?->id,
            'subscription_start_date' => optional($subscriber->subscription_start_date)->format('Y-m-d'),
            'created_at' => $subscriber->created_at?->toISOString(),
            'meter_number' => $subscriber->currentMeter?->unique_code,
            'path_numbers' => $subscriber->currentMeter?->path_number !== null
                ? [(int) $subscriber->currentMeter->path_number] : [],
            'credit_balance' => $summary['total_credit_balance'],
            'debit_balance' => $summary['total_debit_balance'],
            'account_balance' => $summary['account_balance'],
            'general_balance' => $summary['general_balance'],
        ];
    }

    private function meterMinor(Meter $meter): int
    {
        return MoneyBoundary::readDecimal($meter->getRawOriginal('balance'), DecimalDomain::D10,
            $meter->getConnection()->getDriverName(), signed: true);
    }

    private function refundMinor(Payment $payment): int
    {
        return SignedMinor::sum($payment->refunds->map(fn ($refund) => AccountMoney::stored($refund)));
    }

    private function transferMinor(Subscriber $subscriber, string $column): int
    {
        $total = 0;
        foreach (\App\Models\PaymentAccountTransfer::where($column, $subscriber->id)->orderBy('id')->cursor() as $transfer) {
            $total = SignedMinor::add($total, AccountMoney::stored($transfer));
        }
        return $total;
    }

    private function financialSummary(Subscriber $subscriber): array
    {
        $generalBalance = AccountMoney::stored($subscriber, 'balance');
        $meterCredit = 0;
        $meterDebit = 0;
        foreach ($subscriber->meters as $meter) {
            $balance = $this->meterMinor($meter);
            $meterCredit = SignedMinor::add($meterCredit, max(0, $balance));
            $meterDebit = SignedMinor::add($meterDebit, max(0, SignedMinor::negate($balance)));
        }
        $net = $subscriber->getAccountBalanceMinor();
        $money = static fn (int $minor): float => MoneyBoundary::numericMajorOutput($minor);

        return [
            'total_credit_balance' => $money(max(0, $net)),
            'total_debit_balance' => $money(max(0, SignedMinor::negate($net))),
            'account_balance' => $money($net),
            'transferred_in' => $money($this->transferMinor($subscriber, 'to_subscriber_id')),
            'transferred_out' => $money($this->transferMinor($subscriber, 'from_subscriber_id')),
            'general_balance' => $money($generalBalance),
            'general_credit_balance' => $money(max(0, $generalBalance)),
            'general_debit_balance' => $money(max(0, SignedMinor::negate($generalBalance))),
            'meters_credit_balance' => $money($meterCredit),
            'meters_debit_balance' => $money($meterDebit),
        ];
    }

    private function decorateInvoice(Invoice $invoice): void
    {
        $invoice->setAttribute('true_remaining_amount', $invoice->getTrueRemainingAmount());
        $invoice->setAttribute('remaining_amount', $invoice->getRemainingAmount());
        $invoice->setAttribute('collectible_amount', $invoice->getCollectibleAmount());
        $invoice->setAttribute('can_collect', $invoice->canCollect());
        $invoice->setAttribute('is_latest_invoice', $invoice->isLatestInvoice());
    }

    private function invoiceTypeLabel(?string $type): string
    {
        return match ($type) {
            default => 'استهلاك مياه',
        };
    }
}
