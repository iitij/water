<?php

namespace App\Services;

use App\Support\FinancialAmount;
use App\Support\ExactMoney;
use App\Support\DecimalDomain;
use App\Support\MoneyBoundary;
use App\Support\CashCustodyAmounts;
use App\Support\AccountMoney;
use App\Support\SignedMinor;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    /**
     * إعادة توزيع كل السندات الصالحة (غير الملغاة) على الفواتير.
     * السند الملغى يبقى في قاعدة البيانات للأثر، لكنه لا يخصم شيئاً.
     */
    public function recalculateInvoices(Subscriber $subscriber, ?int $meterId = null): void
    {
        // The optional meter ID is retained for existing callers, but it never
        // scopes money. Only the subscriber is the financial account.
        DB::transaction(function () use ($subscriber): void {
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
            Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);

            ['invoices' => $invoices, 'rows' => $allocationRows, 'paid' => $paid,
                'available' => $availableMinor, 'source_amounts' => $sourceAmounts] = $this->allocationPlanLocked($subscriber);
            app(PaymentAllocationHistory::class)->append($subscriber, $allocationRows, $paid, $availableMinor, $sourceAmounts);

            // Persist all allocations before deriving any cumulative status.
            foreach ($invoices as $invoice) {
                $invoice->amount_paid = MoneyBoundary::writeDecimal($paid[$invoice->id], DecimalDomain::D12);
                if ($invoice->isDirty('amount_paid') && ! $invoice->saveQuietly()) {
                    throw new \RuntimeException('Invoice allocation was not persisted.');
                }
            }
            foreach ($invoices as $invoice) {
                $invoice->updateStatus();
            }
        });
    }

    /**
     * Deterministic read-only replay from persisted documents. The caller must
     * already hold the subscriber lock; this method must never acquire the
     * issuance fence after the subscriber or write any allocation rows.
     */
    public function allocationPlanLocked(Subscriber $subscriber): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Allocation planning requires a locked account transaction.');
        }
        $invoices = Invoice::where('subscriber_id', $subscriber->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderBy('bill_date')->orderBy('id')->lockForUpdate()->get();
        $payments = Payment::where('subscriber_id', $subscriber->id)
            ->whereNull('voided_at')
            ->whereNotIn('id', DB::table('payment_account_transfers')->select('payment_id'))
            ->orderBy('payment_date')->orderBy('id')->lockForUpdate()->get();
        // Sum individual raw DECIMAL amounts, not an engine-dependent floating SUM.
        $refunds = [];
        foreach (\App\Models\PaymentRefund::where('subscriber_id', $subscriber->id)->get(['payment_id', 'amount']) as $refund) {
            $minor = AccountMoney::stored($refund);
            if ($minor <= 0) {
                throw new \LogicException('Invalid refund amount.');
            }
            $refunds[$refund->payment_id] = SignedMinor::add($refunds[$refund->payment_id] ?? 0, $minor);
        }
        $credits = $payments->map(function ($payment) use ($refunds) {
            $minor = AccountMoney::stored($payment);
            $refunded = $refunds[$payment->id] ?? 0;
            if ($minor <= 0 || $refunded > $minor) {
                throw new \LogicException('Receipt/refund amounts do not conserve money.');
            }

            return ['minor' => SignedMinor::subtract($minor, $refunded), 'target' => $payment->invoice_id,
                'date' => $payment->payment_date->format('Y-m-d H:i:s'), 'kind' => 0, 'id' => $payment->id];
        })->all();
        foreach (\App\Models\PaymentAccountTransfer::where('to_subscriber_id', $subscriber->id)->lockForUpdate()->get() as $transfer) {
            $minor = AccountMoney::stored($transfer);
            if ($minor <= 0) {
                throw new \LogicException('Invalid incoming transfer amount.');
            }
            $credits[] = ['minor' => $minor, 'target' => null,
                'date' => $transfer->created_at->format('Y-m-d H:i:s'), 'kind' => 1, 'id' => $transfer->id];
        }
        foreach (\App\Models\TreasuryHeldCashCredit::where('subscriber_id', $subscriber->id)->lockForUpdate()->get() as $credit) {
            $minor = AccountMoney::stored($credit);
            if ($minor <= 0) throw new \LogicException('Invalid held cash credit.');
            $credits[] = ['minor' => $minor, 'target' => null,
                'date' => $credit->effective_on.' 00:00:00', 'kind' => 2, 'id' => $credit->id];
        }
        usort($credits, fn ($a, $b) => [$a['date'], $a['kind'], $a['id']] <=> [$b['date'], $b['kind'], $b['id']]);

        // Use integer minor units inside allocation so cents cannot disappear.
        $paid = [];
        $caps = [];
        foreach ($invoices as $invoice) {
            $paid[$invoice->id] = 0;
            $cap = AccountMoney::stored($invoice, 'total_amount');
            if ($cap < 0) {
                throw new \LogicException('Negative invoice allocation cap.');
            }
            $caps[$invoice->id] = $cap;
        }

        $replacements = \App\Models\HistoricalInvoiceCorrection::whereIn('replacement_invoice_id', $invoices->modelKeys())
            ->pluck('replacement_invoice_id', 'original_invoice_id');
        $allocationRows = [];
        $availableMinor = 0;
        $sourceAmounts = [];
        foreach ($credits as $credit) {
            $remaining = $credit['minor'];
            if ($remaining < 0) {
                throw new \LogicException('Negative allocation source.');
            }
            $availableMinor = SignedMinor::add($availableMinor, $remaining);
            $sourceKey = (match ($credit['kind']) {0 => 'payment:', 1 => 'transfer:', 2 => 'held:'}).$credit['id'];
            if (array_key_exists($sourceKey, $sourceAmounts)) {
                throw new \LogicException('Duplicate allocation credit source.');
            }
            $sourceAmounts[$sourceKey] = $remaining;
            $source = [
                'payment_id' => $credit['kind'] === 0 ? $credit['id'] : null,
                'account_transfer_id' => $credit['kind'] === 1 ? $credit['id'] : null,
            ];
            if ($credit['kind'] === 2) $source['held_cash_credit_id'] = $credit['id'];
            $record = function (?int $invoiceId, int $amount) use (&$allocationRows, $source): void {
                if ($amount > 0) {
                    $allocationRows[] = $source + ['invoice_id' => $invoiceId, 'amount_minor' => $amount];
                }
            };
            $target = $credit['target'];
            $target = $target === null ? null : ($replacements[$target] ?? $target);
            if ($target !== null && isset($caps[$target])) {
                $applied = min($remaining, SignedMinor::subtract($caps[$target], $paid[$target]));
                $paid[$target] = SignedMinor::add($paid[$target], $applied);
                $remaining = SignedMinor::subtract($remaining, $applied);
                $record((int) $target, $applied);
            }
            foreach ($invoices as $invoice) {
                if ($remaining === 0) {
                    break;
                }
                $id = $invoice->id;
                $applied = min($remaining, SignedMinor::subtract($caps[$id], $paid[$id]));
                $paid[$id] = SignedMinor::add($paid[$id], $applied);
                $remaining = SignedMinor::subtract($remaining, $applied);
                $record((int) $id, $applied);
            }
            $record(null, $remaining);
        }
        return ['invoices' => $invoices, 'rows' => $allocationRows, 'paid' => $paid,
            'available' => $availableMinor, 'source_amounts' => $sourceAmounts];
    }

    public function distributePayment(Payment $payment): void
    {
        // One replay algorithm for a new receipt, edits, voids and new invoices.
        $this->recalculateInvoices($payment->subscriber);
    }

    /**
     * إلغاء سند قبض. لا يُحذف السند أبداً: يُوسم بالسبب والمستخدم، ثم تُعاد
     * الفواتير والأرصدة كما لو لم يكن، ويُسجَّل الحدث في سجل التدقيق.
     *
     * مرجع: خطة الإصدار 2.0 — الفصل 5.3.
     */
    public function voidPayment(
        Payment $payment,
        string $reason,
        ?int $userId = null,
        string $source = 'api',
    ): Payment {
        $reason = trim($reason);
        if (\Illuminate\Support\Str::length($reason) < 10) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'reason' => ['سبب الإلغاء مطلوب ولا يقل عن 10 أحرف.'],
            ]);
        }

        return DB::transaction(function () use ($payment, $reason, $userId, $source) {
            // Serialize allocation-changing corrections before taking account locks.
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
            Subscriber::query()->lockForUpdate()->findOrFail($payment->subscriber_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (\App\Models\PaymentAccountTransfer::where('payment_id', $payment->id)->exists()
                || DB::table('closed_cash_corrections')->where('duplicate_of_id', $payment->id)->exists()
                || DB::table('late_receipt_reviews')->where('payment_id', $payment->id)->where('status', 'approved')->exists()
                || DB::table('treasury_bank_collections')->where('payment_id', $payment->id)->exists()
                || \App\Models\PaymentRefund::where('payment_id', $payment->id)->exists()) {
                throw ValidationException::withMessages(['payment' => 'السند مرجع لتصحيح مالي مستقل، ولا يجوز إلغاؤه بالطريقة العادية.']);
            }
            if ($payment->voided_at !== null) {
                return $payment;
            }
            if ($payment->cash_session_id) {
                $session = \App\Models\CashSession::query()->lockForUpdate()->findOrFail($payment->cash_session_id);
                if (! $session->isOpen()) {
                    throw ValidationException::withMessages([
                        'cash_session' => 'لا يمكن إلغاء سند في صندوق مغلق؛ يلزم إجراء تسوية مالية مستقل.',
                    ]);
                }
            }

            $before = [
                'receipt_number' => $payment->receipt_number,
                'amount' => (float) $payment->amount,
                'invoice_id' => $payment->invoice_id,
                'meter_id' => $payment->meter_id,
                'cash_session_id' => $payment->cash_session_id,
            ];

            $payment->forceFill([
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by' => $userId,
                'voided_via' => $source,
            ])->saveQuietly();

            // Keep the original posting and append its opposite.
            $transactionService = new TransactionService();
            $transactionService->reverseDocument($payment->subscriber, $payment, 'payment');
            if ($payment->meter_id) {
                if ($meter = \App\Models\Meter::find($payment->meter_id)) {
                    $transactionService->updateMeterBalance($meter);
                }
            }
            if ($payment->subscriber) {
                $transactionService->updateSubscriberBalance($payment->subscriber);
                $this->recalculateInvoices($payment->subscriber, $payment->meter_id);
            }

            app(\App\Services\AuditLogger::class)->recordRequired('payment.voided', [
                'entity_type' => Payment::class,
                'entity_id' => $payment->id,
                'before' => $before,
                'after' => [
                    'voided_at' => optional($payment->voided_at)->toIso8601String(),
                    'void_reason' => $reason,
                    'voided_by' => $userId,
                ],
                'actor_user_id' => $userId,
                'source' => $source,
            ]);

            return $payment->fresh(['subscriber', 'meter', 'invoice', 'user']);
        });
    }

    /**
     * حدّ الإلغاء المالي: السند الذي يبلغ هذا المبلغ أو يتجاوزه يحتاج اعتماد مشرف.
     * القيمة صفر تعني أن الخاصية معطّلة (سلوك v1 متوافق).
     */
    private function voidApprovalThresholdMinor(): int
    {
        // Only an explicitly valid zero disables approval. A malformed setting
        // or failed DB read must not silently remove an approval requirement.
        $minor = FinancialAmount::validated(Setting::get('void_approval_threshold', 0), 'void_approval_threshold')->minor;
        if ($minor < 0) {
            throw ValidationException::withMessages(['void_approval_threshold' => 'حد اعتماد الإلغاء غير صالح.']);
        }

        return $minor;
    }

    public function voidApprovalThreshold(): float
    {
        // Keep the existing public numeric presentation contract.
        return (float) ($this->voidApprovalThresholdMinor() / 100);
    }

    private function exceedsVoidThreshold(Payment $payment, int $threshold): bool
    {
        $minor = FinancialAmount::validated($payment->amount, 'amount')->minor;
        if ($minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'مبلغ السند غير صالح.']);
        }

        return $threshold > 0 && $minor >= $threshold;
    }

    public function requiresVoidApproval(Payment $payment): bool
    {
        return $this->exceedsVoidThreshold($payment, $this->voidApprovalThresholdMinor());
    }

    /**
     * طلب إلغاء سند: يُنفَّذ فوراً إن كان تحت الحد، وإلا يُسجَّل طلباً معلّقاً.
     *
     * @return array{payment: Payment, pending: bool}
     */
    public function requestVoid(
        Payment $payment,
        string $reason,
        ?int $userId = null,
        string $source = 'api',
    ): array {
        return DB::transaction(function () use ($payment, $reason, $userId, $source) {
            // Serialize allocation-changing corrections before taking account locks.
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
            Subscriber::query()->lockForUpdate()->findOrFail($payment->subscriber_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (\App\Models\PaymentAccountTransfer::where('payment_id', $payment->id)->exists()
                || DB::table('closed_cash_corrections')->where('duplicate_of_id', $payment->id)->exists()
                || DB::table('late_receipt_reviews')->where('payment_id', $payment->id)->where('status', 'approved')->exists()
                || DB::table('treasury_bank_collections')->where('payment_id', $payment->id)->exists()
                || \App\Models\PaymentRefund::where('payment_id', $payment->id)->exists()) {
                throw ValidationException::withMessages(['payment' => 'السند مرجع لتصحيح مالي مستقل، ولا يجوز إلغاؤه بالطريقة العادية.']);
            }
            $reason = trim($reason);
            if (\Illuminate\Support\Str::length($reason) < 10) {
                throw ValidationException::withMessages([
                    'reason' => ['سبب الإلغاء مطلوب ولا يقل عن 10 أحرف.'],
                ]);
            }

            // An existing pending approval must not be bypassed by a later
            // change to the configuration. Replays retain their prior state.
            if ($payment->hasPendingVoidRequest()) {
                return ['payment' => $payment, 'pending' => true];
            }
            if ($payment->isVoided()) {
                return ['payment' => $payment, 'pending' => false];
            }

            $thresholdMinor = $this->voidApprovalThresholdMinor();
            if (! $this->exceedsVoidThreshold($payment, $thresholdMinor)) {
                return ['payment' => $this->voidPayment($payment, $reason, $userId, $source), 'pending' => false];
            }

            $payment->forceFill([
                'void_requested_at' => now(),
                'void_requested_by' => $userId,
                'void_request_reason' => $reason,
                'void_decision_notes' => null,
                'void_decided_by' => null,
                'void_decided_at' => null,
            ])->saveQuietly();

            app(AuditLogger::class)->recordRequired('payment.void_requested', [
                'entity_type' => Payment::class,
                'entity_id' => $payment->id,
                'after' => [
                    'receipt_number' => $payment->receipt_number,
                    'amount' => (float) $payment->amount,
                    'reason' => $reason,
                    'threshold' => (float) ($thresholdMinor / 100),
                    'threshold_minor' => $thresholdMinor,
                ],
                'actor_user_id' => $userId,
                'source' => $source,
            ]);

            return ['payment' => $payment->fresh(), 'pending' => true];
        }, 3);
    }

    /**
     * قرار المشرف في طلب إلغاء معلّق.
     *
     * الموافقة تستخدم نفس مسار الإلغاء بالحركة العكسية وإعادة الاحتساب
     * فلا يوجد مساران مختلفان لإلغاء سند.
     */
    public function decideVoidRequest(
        Payment $payment,
        bool $approve,
        User $actor,
        ?string $notes = null,
        string $source = 'api',
    ): Payment {
        return DB::transaction(function () use ($payment, $approve, $actor, $notes, $source) {
            // Serialize allocation-changing corrections before taking account locks.
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Financial issuance lock is missing.');
            }
            Subscriber::query()->lockForUpdate()->findOrFail($payment->subscriber_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (\App\Models\PaymentAccountTransfer::where('payment_id', $payment->id)->exists()
                || DB::table('closed_cash_corrections')->where('duplicate_of_id', $payment->id)->exists()
                || DB::table('late_receipt_reviews')->where('payment_id', $payment->id)->where('status', 'approved')->exists()
                || DB::table('treasury_bank_collections')->where('payment_id', $payment->id)->exists()
                || \App\Models\PaymentRefund::where('payment_id', $payment->id)->exists()) {
                throw ValidationException::withMessages(['payment' => 'السند مرجع لتصحيح مالي مستقل، ولا يجوز إلغاؤه بالطريقة العادية.']);
            }

            if ($payment->isVoided()) {
                return $payment;
            }

            if (! $payment->hasPendingVoidRequest()) {
                throw ValidationException::withMessages([
                    'payment' => ['لا يوجد طلب إلغاء معلّق على هذا السند.'],
                ]);
            }

            $reason = (string) ($payment->void_request_reason ?? '');
            $notes = $notes === null ? null : trim($notes);

            if ($approve) {
                $voider = $actor;
                $payment = $this->voidPayment($payment, $reason, $voider->id, 'approval');
                $payment->forceFill([
                    'void_decision_notes' => $notes,
                    'void_decided_by' => $actor->id,
                    'void_decided_at' => now(),
                ])->saveQuietly();

                app(AuditLogger::class)->recordRequired('payment.void_approved', [
                    'entity_type' => Payment::class,
                    'entity_id' => $payment->id,
                    'after' => [
                        'receipt_number' => $payment->receipt_number,
                        'reason' => $reason,
                        'notes' => $notes,
                    ],
                    'actor_user_id' => $actor->id,
                    'source' => $source,
                ]);

                return $payment->fresh(['subscriber', 'meter', 'invoice', 'user']);
            }

            $payment->forceFill([
                'void_requested_at' => null,
                'void_requested_by' => null,
                'void_request_reason' => null,
                'void_decision_notes' => $notes,
                'void_decided_by' => $actor->id,
                'void_decided_at' => now(),
            ])->saveQuietly();

            app(AuditLogger::class)->recordRequired('payment.void_rejected', [
                'entity_type' => Payment::class,
                'entity_id' => $payment->id,
                'after' => [
                    'receipt_number' => $payment->receipt_number,
                    'notes' => $notes,
                ],
                'actor_user_id' => $actor->id,
                'source' => $source,
            ]);

            return $payment->fresh(['subscriber', 'meter', 'invoice', 'user']);
        }, 3);
    }

    /**
     * تحصيل دفعة من مشترك
     */
    public function collectPayment(
        Subscriber $subscriber,
        mixed $amount,
        ?int $invoiceId = null,
        string $paymentMethod = 'cash',
        ?string $referenceNumber = null,
        ?string $notes = null,
        ?string $reason = null,
        ?int $userId = null,
        ?string $paymentDate = null,
        ?int $requestedMeterId = null,
        ?string $syncUuid = null,
        ?int $requestedCashSessionId = null,
        bool $deferred = false,
        ?int $lateReviewId = null,
    ): Payment {
        return $this->postPayment($subscriber, $amount, $invoiceId, $paymentMethod, $referenceNumber, $notes, $reason,
            $userId, $paymentDate, $requestedMeterId, $syncUuid, $requestedCashSessionId, $deferred, $lateReviewId);
    }

    private function postPayment(
        Subscriber $subscriber,
        mixed $amount,
        ?int $invoiceId = null,
        string $paymentMethod = 'cash',
        ?string $referenceNumber = null,
        ?string $notes = null,
        ?string $reason = null,
        ?int $userId = null,
        ?string $paymentDate = null,
        ?int $requestedMeterId = null,
        ?string $syncUuid = null,
        ?int $requestedCashSessionId = null,
        bool $deferred = false,
        ?int $lateReviewId = null,
        bool $clearedClaim = false,
    ): Payment {
        $syncUuid = $syncUuid === null ? null : strtolower($syncUuid);
        if ($syncUuid !== null && ! \Illuminate\Support\Str::isUuid($syncUuid)) {
            throw ValidationException::withMessages(['sync_uuid' => 'معرّف المزامنة غير صالح.']);
        }
        $money = FinancialAmount::validated($amount);
        if ($money->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'المبلغ يجب أن يكون موجباً وبحد أقصى منزلتين عشريتين.']);
        }
        $amount = $money->decimal();
        return DB::transaction(function () use ($subscriber, $amount, $money, $invoiceId, $paymentMethod, $referenceNumber, $notes, $reason, $userId, $paymentDate, $requestedMeterId, $syncUuid, $requestedCashSessionId, $deferred, $lateReviewId, $clearedClaim) {
            // Acquire the shared issuance fence before subscriber/business locks.
            // Scope-gated writers already hold it; nested acquisition is reentrant.
            $issuer = DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first();
            if (! $issuer) {
                throw new \LogicException('Payment issuance lock is missing.');
            }
            Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);

            $review = $syncUuid ? DB::table('late_receipt_reviews')->where('sync_uuid', $syncUuid)->lockForUpdate()->first() : null;
            if ($lateReviewId !== null) {
                abort_unless($review && (int) $review->id === $lateReviewId && $review->status === 'pending'
                    && (int) $review->subscriber_id === (int) $subscriber->id
                    && (int) $review->collector_id === (int) $userId
                    && (int) ($review->invoice_id ?? 0) === (int) ($invoiceId ?? 0)
                    && CashCustodyAmounts::positiveStored($review->amount, DB::connection()->getDriverName(), DecimalDomain::D12) === $money->minor
                    && $review->payment_method === $paymentMethod
                    && ($clearedClaim && in_array($paymentMethod, ['bank_transfer', 'check'], true)
                        ? $paymentDate !== null && $paymentDate >= $review->payment_date : $review->payment_date === $paymentDate)
                    && ($review->reference_number ?? '') === ($referenceNumber ?? '')
                    && ($review->reason ?? '') === ($reason ?? '')
                    && $requestedMeterId === null && $requestedCashSessionId === null, 409, 'بيانات التسوية الاستثنائية غير مطابقة.');
            }

            if ($syncUuid) {
                $existing = Payment::withTrashed()->where('sync_uuid', $syncUuid)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->trashed()) {
                        abort(409, 'عملية الدفع المرتبطة بمعرّف المزامنة محذوفة مسبقاً.');
                    }
                    $approvedReview = $review && $review->status === 'approved' && (int) $review->payment_id === (int) $existing->id;
                    if ((int) $existing->user_id !== (int) ($userId ?? 0)
                        || (int) $existing->subscriber_id !== (int) $subscriber->id
                        || (int) ($existing->invoice_id ?? 0) !== (int) ($invoiceId ?? 0)
                        || FinancialAmount::parse($existing->amount)->minor !== $money->minor
                        || (! $invoiceId && (int) (($approvedReview ? $review->submitted_meter_id : ($existing->meter_id ?? $existing->retired_meter_id)) ?? 0) !== (int) ($requestedMeterId ?? 0))
                        || ($requestedCashSessionId !== null && (int) ($approvedReview ? $review->cash_session_id : $existing->cash_session_id) !== $requestedCashSessionId)
                        || $existing->payment_method !== $paymentMethod
                        || ($paymentDate !== null && ($approvedReview ? $review->payment_date : $existing->payment_date->toDateString()) !== \Carbon\CarbonImmutable::parse($paymentDate)->toDateString())
                        || ($existing->reference_number ?? '') !== ($referenceNumber ?? '')
                        || ($existing->reason ?? '') !== ($reason ?? '')) {
                        abort(409, 'معرّف المزامنة مستخدم مسبقاً لعملية دفع مختلفة.');
                    }
                    abort_if($lateReviewId !== null, 409, 'سبق ترحيل هذا السند؛ يلزم التحقق من السجل الأصلي.');
                    return $existing;
                }
            }
            abort_if($review && $lateReviewId === null, 409, 'السند مرتبط بمراجعة مالية؛ لا يجوز ترحيله خارجها.');

            if (! in_array($paymentMethod, ['cash', 'bank_transfer', 'check'], true)
                || ($paymentMethod !== 'cash' && ! $clearedClaim)) {
                throw ValidationException::withMessages(['payment_method' => 'التحصيل غير النقدي الجديد يتطلب طلب تحصيل معلق وإثبات وصول الأموال.']);
            }
            if ($syncUuid && ! $clearedClaim && DB::table('noncash_collection_claims')->where('sync_uuid', $syncUuid)->exists()) {
                abort(409, 'معرّف المزامنة مرتبط بطلب تحصيل غير نقدي.');
            }

            // A delayed operation must never be silently assigned to today's till.
            if ($deferred && ($requestedCashSessionId === null || ! $syncUuid || $paymentDate === null)) {
                abort(409, 'سند مؤجل بلا هوية صندوق أو تاريخ قبض ثابت؛ يلزم مراجعة مالية.');
            }
            if ($requestedCashSessionId !== null && ! $userId) {
                abort(409, 'يلزم تحديد المحصّل صاحب الصندوق.');
            }

            // Acquire collector/till locks before financial reads and number generation.
            $cashSessionId = null;
            if ($userId && $lateReviewId === null && ! $clearedClaim) {
                User::query()->lockForUpdate()->findOrFail($userId);
                $cashSession = \App\Models\CashSession::query()
                    ->where('user_id', $userId)
                    ->where('state', \App\Models\CashSession::STATE_OPEN)
                    ->latest('id')->lockForUpdate()->first();
                if ($requestedCashSessionId !== null && (int) ($cashSession?->id ?? 0) !== $requestedCashSessionId) {
                    abort(409, 'الصندوق الأصلي غير مفتوح أو لا يتبع المحصّل؛ احتفظ بالسند للمراجعة المالية ولا تنقله إلى صندوق آخر.');
                }
                if ($requestedCashSessionId !== null && $paymentDate !== null
                    && \Carbon\CarbonImmutable::parse($paymentDate)->toDateString() < $cashSession->session_date->toDateString()) {
                    abort(409, 'تاريخ القبض يسبق تاريخ فتح الصندوق الأصلي.');
                }
                $cashSessionId = $cashSession?->id;
            }

            // العداد مرجع للحركة فقط؛ الحساب المالي هو المشترك.
            $meterId = $requestedMeterId;
            $meter = $meterId ? \App\Models\Meter::where('subscriber_id', $subscriber->id)->findOrFail($meterId) : null;
            if ($invoiceId) {
                $invoice = Invoice::where('subscriber_id', $subscriber->id)->whereNotIn('status', ['draft', 'cancelled'])->findOrFail($invoiceId);
                $meterId = $invoice->meter_id;
                $meter = $invoice->meter;
            }

            // 1. إنشاء سجل الدفع
            $issuedAt = now();
            $payment = Payment::create([
                'subscriber_id' => $subscriber->id,
                'meter_id' => $meterId,
                'invoice_id' => $invoiceId,
                'user_id' => $userId,
                'cash_session_id' => $cashSessionId,
                'receipt_number' => app(DocumentSequenceService::class)->reserveForIssue('REC', $issuedAt->format('Ym')),
                'amount' => $amount,
                'payment_date' => $paymentDate ?? $issuedAt->toDateString(),
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,
                'notes' => $notes,
                'reason' => $reason,
                'sync_uuid' => $syncUuid,
            ]);

            // 2. توزيع الدفعة على الفواتير المستحقة
            $this->distributePayment($payment);

            // 3. تسجيل العملية في دفتر الأستاذ (الـ Ledger) مع تمرير العداد
            $transactionService = new TransactionService();
            $transactionService->recordTransaction(
                subscriber: $subscriber,
                type: 'payment',
                amount: $amount, // كامل المبلغ المدفوع
                description: 'سند قبض رقم ' . $payment->receipt_number,
                reference: $payment,
                meter: $meter
            );

            // Dispatch WhatsApp Notification (if enabled)
            // Removed: WhatsApp is now click-to-chat

            if (app(\App\Services\WhatsAppService::class)->isEnabled() && in_array(app(\App\Services\WhatsAppService::class)->getMethod(), ['meta_api', 'local_api'])) {
                if (\App\Models\Setting::get('auto_send_whatsapp_payment', false)) {
                    \App\Jobs\SendWhatsAppPaymentJob::dispatch($payment)->afterCommit();
                }
            }

            return $payment;
        }, 3);
    }

    /** The only new noncash receipt issuance path; proof and both ledgers commit together. */
    public function clearNonCashClaim(User $actor, int $id, int $statementId, int $lineId, string $reference, string $evidence): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $candidate = DB::table('noncash_collection_claims')->find($id);
        abort_unless($candidate, 404);
        return DB::transaction(function () use ($actor, $id, $statementId, $lineId, $reference, $evidence, $candidate): array {
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Payment issuance lock missing.');
            }
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
            $claim = DB::table('noncash_collection_claims')->where('id', $id)->lockForUpdate()->first();
            abort_unless((int) $claim->subscriber_id === (int) $subscriber->id, 409);
            abort_if((int) $claim->submitted_by === (int) $actor->id, 403, 'لا يجوز اعتماد طلبك بنفسك.');
            $bank = app(BankReconciliationService::class);
            if ($claim->status === 'cleared') {
                abort_unless((int) $claim->decided_by === (int) $actor->id, 409);
                $result = $bank->confirmCollection($actor, $statementId, $lineId, (int) $claim->payment_id, $reference, $evidence);
                return ['claim' => (array) $claim, 'payment_id' => (int) $claim->payment_id, 'bank' => $result];
            }
            abort_unless($claim->status === 'pending', 409, 'سبق رفض الطلب.');
            $line = DB::table('bank_statement_lines')->where('bank_statement_id', $statementId)->where('id', $lineId)->lockForUpdate()->first();
            abort_unless($line, 404);
            $minor = MoneyBoundary::readMinor($claim->amount_minor, true, DecimalDomain::D12);
            if ($minor <= 0 || MoneyBoundary::readMinor($line->amount_minor, false, DecimalDomain::D14) !== $minor || $line->booked_on < $claim->instrument_on) {
                throw ValidationException::withMessages(['line_id' => 'يجب مطابقة مبلغ الطلب وتاريخ تحقق التحصيل.']);
            }
            abort_if(Payment::withTrashed()->where('sync_uuid', $claim->sync_uuid)->exists()
                || DB::table('late_receipt_reviews')->where('sync_uuid', $claim->sync_uuid)->exists(), 409, 'معرف الطلب مستخدم في سند آخر.');
            // No till assignment. The actual clearance date, not the submission date,
            // determines receipt date. The bank service independently verifies the statement.
            $payment = $this->postPayment($subscriber, MoneyBoundary::writeDecimal($minor, DecimalDomain::D12),
                $claim->invoice_id ? (int) $claim->invoice_id : null, $claim->payment_method,
                $claim->instrument_reference, $claim->notes, null, (int) $claim->submitted_by,
                $line->booked_on, null, $claim->sync_uuid, null, false, null, true);
            // Link the decision before bank validation so the bank audit can verify
            // the complete chain. The outer transaction rolls it back on any failure.
            $decision = ['status' => 'cleared', 'payment_id' => $payment->id, 'decided_by' => $actor->id,
                'decision_evidence' => trim($evidence), 'decided_at' => now(), 'updated_at' => now()];
            DB::table('noncash_collection_claims')->where('id', $id)->update($decision);
            $result = $bank->confirmCollection($actor, $statementId, $lineId, $payment->id, $reference, $evidence);
            app(TreasuryRegisterService::class)->requiredAudit('noncash_claim.cleared', [
                'entity_type' => 'noncash_collection_claim', 'entity_id' => $id, 'actor_user_id' => $actor->id,
                'after' => $decision + ['bank_collection_id' => $result['collection']['id']],
            ]);
            return ['claim' => (array) DB::table('noncash_collection_claims')->find($id), 'payment_id' => $payment->id, 'bank' => $result];
        }, 3);
    }

    /** Resolve a late, unposted noncash receipt against actual bank clearance. */
    public function clearLateNonCash(User $actor, int $id, int $statementId, int $lineId, string $reviewReference, string $bankReference, string $evidence): array
    {
        app(TreasuryRegisterService::class)->authorize($actor);
        $reviewReference = mb_strtoupper(trim($reviewReference));
        $bankReference = mb_strtoupper(trim($bankReference));
        $evidence = trim($evidence);
        if (mb_strlen($reviewReference) < 3 || mb_strlen($reviewReference) > 150
            || mb_strlen($bankReference) < 3 || mb_strlen($bankReference) > 150
            || $reviewReference === $bankReference || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 2000) {
            throw ValidationException::withMessages(['evidence_reference' => 'يلزم مرجعان مستقلان ودليل صالح.']);
        }
        $candidate = DB::table('late_receipt_reviews')->find($id);
        abort_unless($candidate, 404);
        return DB::transaction(function () use ($actor, $id, $statementId, $lineId, $reviewReference, $bankReference, $evidence, $candidate): array {
            if (! DB::table('payment_issuance_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new \LogicException('Payment issuance lock missing.');
            }
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($candidate->subscriber_id);
            $review = DB::table('late_receipt_reviews')->where('id', $id)->lockForUpdate()->first();
            abort_unless((int) $review->subscriber_id === (int) $subscriber->id, 409);
            abort_if((int) $review->collector_id === (int) $actor->id, 403, 'لا يجوز اعتماد مطالبتك بنفسك.');
            $bank = app(BankReconciliationService::class);
            if ($review->status !== 'pending') {
                abort_unless($review->status === 'approved' && $review->settlement_method === 'verified_non_cash'
                    && (int) $review->decided_by === (int) $actor->id && $review->payment_id
                    && $review->evidence_reference === $reviewReference && $review->bank_clearance_reference === $bankReference
                    && $review->evidence === $evidence, 409, 'سبق البت في المطالبة بطريقة مختلفة.');
                return ['review' => (array) $review, 'bank' => $bank->confirmCollection($actor, $statementId, $lineId,
                    (int) $review->payment_id, $bankReference, $evidence)];
            }
            $session = \App\Models\CashSession::query()->lockForUpdate()->findOrFail($review->cash_session_id);
            abort_unless($session->isApproved() && (int) $session->user_id === (int) $review->collector_id,
                409, 'يلزم اعتماد الصندوق الأصلي أولاً.');
            $instrument = mb_strtoupper(trim((string) $review->reference_number));
            if (! in_array($review->payment_method, ['bank_transfer', 'check'], true)
                || mb_strlen($instrument) < 3 || mb_strlen($instrument) > 150) {
                throw ValidationException::withMessages(['payment_method' => 'يلزم مطالبة غير نقدية بمرجع وسيلة تحصيل موثق.']);
            }
            $line = DB::table('bank_statement_lines')->where('bank_statement_id', $statementId)->where('id', $lineId)->lockForUpdate()->first();
            abort_unless($line, 404);
            $minor = CashCustodyAmounts::positiveStored($review->amount, DB::connection()->getDriverName(), DecimalDomain::D12);
            if ($minor <= 0 || MoneyBoundary::readMinor($line->amount_minor, false, DecimalDomain::D14) !== $minor || $line->booked_on < $review->payment_date) {
                throw ValidationException::withMessages(['line_id' => 'يجب مطابقة مبلغ المطالبة وتاريخ تحقق التحصيل.']);
            }
            abort_if(Payment::withTrashed()->where('sync_uuid', $review->sync_uuid)->exists()
                || Payment::withTrashed()->where('payment_method', $review->payment_method)
                    ->whereRaw('UPPER(TRIM(reference_number)) = ?', [$instrument])->exists()
                || DB::table('noncash_collection_claims')->where('sync_uuid', $review->sync_uuid)->exists()
                || DB::table('noncash_collection_claims')->where('payment_method', $review->payment_method)
                    ->where('instrument_reference', $instrument)->exists(), 409, 'معرّف أو مرجع التحصيل مستخدم مسبقاً.');
            abort_if(DB::table('late_receipt_reviews')->where('evidence_reference', $reviewReference)->exists()
                || DB::table('treasury_accounts')->where('opening_reference', $reviewReference)->exists()
                || DB::table('treasury_postings')->where('evidence_reference', $reviewReference)->exists()
                || DB::table('cash_handovers')->where('reference', $reviewReference)->exists()
                || DB::table('payment_refunds')->where('disbursement_reference', $reviewReference)->exists(),
                409, 'مرجع المراجعة مستخدم مسبقاً.');
            $payment = $this->postPayment($subscriber, MoneyBoundary::writeDecimal($minor, DecimalDomain::D12),
                $review->invoice_id ? (int) $review->invoice_id : null, $review->payment_method,
                $review->reference_number, $review->notes, $review->reason, (int) $review->collector_id,
                $line->booked_on, null, $review->sync_uuid, null, false, $id, true);
            // The bank verifier checks the approved review against the receipt.
            // Both changes remain invisible until the outer transaction commits.
            $decision = ['status' => 'approved', 'payment_id' => $payment->id,
                'decided_by' => $actor->id, 'decided_at' => now(), 'settlement_method' => 'verified_non_cash',
                'evidence_reference' => $reviewReference, 'bank_clearance_reference' => $bankReference,
                'evidence' => $evidence, 'updated_at' => now()];
            DB::table('late_receipt_reviews')->where('id', $id)->update($decision);
            $result = $bank->confirmCollection($actor, $statementId, $lineId, $payment->id, $bankReference, $evidence);
            app(TreasuryRegisterService::class)->requiredAudit('late_receipt.approved', [
                'entity_type' => 'late_receipt_review', 'entity_id' => $id, 'actor_user_id' => $actor->id,
                'before' => (array) $review, 'after' => $decision + [
                    'new_cash_movement' => false, 'bank_collection_id' => $result['collection']['id'],
                ],
            ]);
            return ['review' => (array) DB::table('late_receipt_reviews')->find($id), 'bank' => $result];
        }, 3);
    }
}
