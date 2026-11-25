<?php

namespace App\Services;

use App\Support\FinancialAmount;
use App\Support\ExactMoney;
use App\Support\AccountMoney;
use App\Support\SignedMinor;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAccountTransfer;
use App\Models\PaymentRefund;
use App\Models\Subscriber;
use Illuminate\Validation\ValidationException;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;

class TransactionService
{
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['transaction' => $message]);
    }

    private function minor(mixed $amount): int
    {
        return FinancialAmount::validated($amount, 'transaction')->minor;
    }

    private function stored(Model $model, string $field = 'amount'): int
    {
        try {
            return AccountMoney::stored($model, $field);
        } catch (\InvalidArgumentException|\OverflowException) {
            $this->reject('قيمة مالية مخزنة غير صالحة؛ يلزم التدقيق دون إعادة تقريبها.');
        }
    }

    /** Match financial postings to the persisted document, not caller-supplied amounts. */
    private function validateDocument(Subscriber $subscriber, string $type, string $amount, ?Model $reference, ?\App\Models\Meter $meter): ?Model
    {
        $minor = $this->minor($amount);
        $documentTypes = ['invoice', 'invoice_reversal', 'payment', 'payment_reversal', 'payment_transfer_in', 'payment_refund'];
        $isDocument = $reference instanceof Invoice || $reference instanceof Payment
            || $reference instanceof PaymentAccountTransfer || $reference instanceof PaymentRefund;
        if (! $isDocument) {
            if (in_array($type, $documentTypes, true)) {
                $this->reject('الحركة المالية تتطلب مستنداً معتمداً من النوع المطابق.');
            }
            if ($meter && (int) $meter->subscriber_id !== (int) $subscriber->id) {
                $this->reject('العداد لا يتبع حساب المشترك.');
            }
            return $reference;
        }
        $doc = $reference->newQuery()->lockForUpdate()->find($reference->getKey());
        if (! $doc) {
            $this->reject('المستند المالي غير موجود أو محذوف.');
        }
        $owner = $doc->subscriber_id;
        $meterId = $doc->meter_id;
        if ($doc instanceof Invoice) {
            if (! in_array($type, ['invoice', 'invoice_reversal'], true) || $doc->status === 'draft'
                || ($type === 'invoice' && $doc->status === 'cancelled')) {
                $this->reject('نوع حركة الفاتورة أو حالتها غير صالح.');
            }
            $expected = $this->stored($doc, 'total_amount') * ($type === 'invoice' ? -1 : 1);
            if ($expected > 0 && $type === 'invoice' || $expected < 0 && $type === 'invoice_reversal') {
                $this->reject('قيمة الفاتورة سالبة.');
            }
        } elseif ($doc instanceof Payment) {
            if (! in_array($type, ['payment', 'payment_reversal'], true) || $this->stored($doc) <= 0
                || ($type === 'payment' && $doc->isVoided())) {
                $this->reject('نوع حركة سند القبض أو حالته غير صالح.');
            }
            $expected = $this->stored($doc) * ($type === 'payment' ? 1 : -1);
        } elseif ($doc instanceof PaymentAccountTransfer) {
            if ($type !== 'payment_transfer_in' || $this->stored($doc) <= 0) {
                $this->reject('نوع حركة التحويل أو مبلغها غير صالح.');
            }
            $owner = $doc->to_subscriber_id;
            $meterId = null;
            $expected = $this->stored($doc);
        } else {
            if ($type !== 'payment_refund' || $this->stored($doc) <= 0) {
                $this->reject('نوع حركة الاسترداد أو مبلغها غير صالح.');
            }
            $meterId = null;
            $expected = -$this->stored($doc);
        }
        if ((int) $owner !== (int) $subscriber->id || (int) ($meterId ?? 0) !== (int) ($meter?->id ?? 0)
            || ($meter && (int) $meter->subscriber_id !== (int) $subscriber->id) || $minor !== $expected) {
            $this->reject('الحساب أو العداد أو مبلغ الحركة لا يطابق المستند المالي الأصلي.');
        }
        return $doc;
    }

    /**
     * Record a new transaction in the ledger and update subscriber balance.
     */
    public function recordTransaction(
        Subscriber $subscriber,
        string $type,
        mixed $amount,
        ?string $description = null,
        ?Model $reference = null,
        ?\App\Models\Meter $meter = null
    ): Transaction {
        $amount = FinancialAmount::validated($amount, 'transaction')->decimal();
        return \Illuminate\Support\Facades\DB::transaction(function () use ($subscriber, $type, $amount, $description, $reference, $meter) {
            Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);
            $reference = $this->validateDocument($subscriber, $type, $amount, $reference, $meter);

            if ($reference instanceof \App\Models\Invoice || $reference instanceof \App\Models\Payment
                || $reference instanceof \App\Models\PaymentAccountTransfer
                || $reference instanceof \App\Models\PaymentRefund) {
                $existing = Transaction::withTrashed()
                    ->where('reference_type', get_class($reference))
                    ->where('reference_id', $reference->id)
                    ->where('type', $type)->lockForUpdate()->get();
                if ($existing->count() > 1) {
                    $this->reject('توجد حركات مكررة للمستند؛ يلزم مراجعة دفتر الأستاذ.');
                }
                if ($existing = $existing->first()) {
                    if ($existing->trashed()
                        || (int) $existing->subscriber_id !== (int) $subscriber->id
                        || (int) ($existing->meter_id ?? 0) !== (int) ($meter?->id ?? 0)
                        || $this->stored($existing) !== $this->minor($amount)
                        || (int) ($existing->retired_meter_id ?? 0) !== (int) ($reference->retired_meter_id ?? 0)) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'transaction' => 'المستند المالي سبق ترحيله ببيانات مختلفة.',
                        ]);
                    }
                    return $existing;
                }
            }
            $transaction = new Transaction([
            'subscriber_id' => $subscriber->id,
            'meter_id' => $meter ? $meter->id : null,
            'type' => $type,
            'amount' => $amount, // Positive for credit, Negative for debit
            'description' => $description,
            'reference_id' => $reference?->id,
            'reference_type' => $reference ? get_class($reference) : null,
        ]);
            if ($reference instanceof Invoice || $reference instanceof Payment) {
                $transaction->forceFill(['retired_meter_id' => $reference->retired_meter_id, 'retired_meter_code' => $reference->retired_meter_code]);
            }
            if (! $transaction->save() || ! $transaction->exists || $transaction->getKey() === null) {
                throw new \RuntimeException('Ledger posting was not persisted.');
            }

        if ($meter) {
            $this->updateMeterBalance($meter);
        } else {
            $this->updateSubscriberBalance($subscriber);
        }

            return $transaction;
        }, 3);
    }

    /**
     * Reverse exactly one original posting, keeping both ledger rows active.
     * The caller holds the subscriber lock and changes document state in the
     * same transaction. Historical missing/deleted originals are not guessed.
     */
    public function reverseDocument(Subscriber $subscriber, Model $reference, string $originalType): Transaction
    {
        if (! (($originalType === 'invoice' && $reference instanceof Invoice) || ($originalType === 'payment' && $reference instanceof Payment))) {
            throw new \InvalidArgumentException('Unsupported reversal type.');
        }
        return \Illuminate\Support\Facades\DB::transaction(function () use ($subscriber, $reference, $originalType) {
            Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);
            $reference = $reference->newQuery()->lockForUpdate()->findOrFail($reference->getKey());
            $originals = Transaction::withTrashed()->where('reference_type', get_class($reference))
                ->where('reference_id', $reference->id)->where('type', $originalType)
                ->lockForUpdate()->get();
            $expected = $originalType === 'invoice' ? -$this->stored($reference, 'total_amount') : $this->stored($reference);
            if ($originals->count() !== 1
                || $originals->first()->trashed()
                || (int) $originals->first()->subscriber_id !== (int) $subscriber->id
                || $this->stored($originals->first()) !== $expected
                || (int) ($originals->first()->meter_id ?? 0) !== (int) ($reference->meter_id ?? 0)
                || (int) ($originals->first()->retired_meter_id ?? 0) !== (int) ($reference->retired_meter_id ?? 0)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'transaction' => 'لا يمكن الإلغاء قبل التحقق من الحركة المالية الأصلية.',
                ]);
            }
            $original = $originals->first();
            $reversal = $this->recordTransaction(
                subscriber: $subscriber,
                type: $originalType.'_reversal',
                amount: ExactMoney::fromMinor(-$this->stored($original))->decimal(),
                description: 'عكس الحركة المالية رقم '.$original->id,
                reference: $reference,
                meter: $original->meter,
            );
            return $reversal;
        }, 3);
    }

    /** Exact unified ledger balance for financial writers under the subscriber lock. */
    public function accountBalanceMinor(Subscriber $subscriber): int
    {
        return $this->ledgerMinor(Transaction::where('subscriber_id', $subscriber->id));
    }

    private function ledgerMinor(\Illuminate\Database\Eloquent\Builder $query): int
    {
        $total = 0;
        foreach ($query->orderBy('id')->cursor() as $entry) {
            $total = SignedMinor::add($total, $this->stored($entry));
        }

        return $total;
    }

    /**
     * Recalculate and update the subscriber's general balance (meter_id is null).
     */
    public function updateSubscriberBalance(Subscriber $subscriber): void
    {
        $totalBalance = FinancialAmount::storageDecimal(
            $this->ledgerMinor(Transaction::where('subscriber_id', $subscriber->id)->whereNull('meter_id')),
            'subscriber_balance'
        );
        
        if (! $subscriber->updateQuietly(['balance' => $totalBalance])) {
            throw new \RuntimeException('Subscriber balance cache was not persisted.');
        }
    }

    /**
     * Recalculate and update the meter's balance cache based on transactions.
     */
    public function updateMeterBalance(\App\Models\Meter $meter): void
    {
        $totalBalance = FinancialAmount::storageDecimal(
            $this->ledgerMinor(Transaction::where('meter_id', $meter->id)),
            'meter_balance',
            FinancialAmount::METER_BALANCE_MAX
        );
        
        if (! $meter->updateQuietly(['balance' => $totalBalance])) {
            throw new \RuntimeException('Meter balance cache was not persisted.');
        }
    }
}
