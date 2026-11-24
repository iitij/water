<?php

namespace App\Services;

use App\Models\Subscriber;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;

class TransactionService
{
    /**
     * Record a new transaction in the ledger and update subscriber balance.
     */
    public function recordTransaction(
        Subscriber $subscriber,
        string $type,
        float $amount,
        string $description = null,
        ?Model $reference = null,
        ?\App\Models\Meter $meter = null
    ): Transaction {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($subscriber, $type, $amount, $description, $reference, $meter) {
            Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);

            if ($reference instanceof \App\Models\Invoice || $reference instanceof \App\Models\Payment) {
                $existing = Transaction::withTrashed()
                    ->where('reference_type', get_class($reference))
                    ->where('reference_id', $reference->id)
                    ->where('type', $type)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->trashed()
                        || (int) $existing->subscriber_id !== (int) $subscriber->id
                        || (int) ($existing->meter_id ?? 0) !== (int) ($meter?->id ?? 0)
                        || round((float) $existing->amount, 2) !== round($amount, 2)) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'transaction' => 'المستند المالي سبق ترحيله ببيانات مختلفة.',
                        ]);
                    }
                    return $existing;
                }
            }
            $transaction = Transaction::create([
            'subscriber_id' => $subscriber->id,
            'meter_id' => $meter ? $meter->id : null,
            'type' => $type,
            'amount' => $amount, // Positive for credit, Negative for debit
            'description' => $description,
            'reference_id' => $reference?->id,
            'reference_type' => $reference ? get_class($reference) : null,
        ]);

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
        if (! in_array($originalType, ['invoice', 'payment'], true)) {
            throw new \InvalidArgumentException('Unsupported reversal type.');
        }
        return \Illuminate\Support\Facades\DB::transaction(function () use ($subscriber, $reference, $originalType) {
            Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);
            $originals = Transaction::where('reference_type', get_class($reference))
                ->where('reference_id', $reference->id)->where('type', $originalType)
                ->lockForUpdate()->get();
            $expected = $originalType === 'invoice' ? -(float) $reference->total_amount : (float) $reference->amount;
            if ($originals->count() !== 1
                || (int) $originals->first()->subscriber_id !== (int) $subscriber->id
                || round((float) $originals->first()->amount, 2) !== round($expected, 2)
                || (int) ($originals->first()->meter_id ?? 0) !== (int) ($reference->meter_id ?? 0)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'transaction' => 'لا يمكن الإلغاء قبل التحقق من الحركة المالية الأصلية.',
                ]);
            }
            $original = $originals->first();
            $reversal = $this->recordTransaction(
                subscriber: $subscriber,
                type: $originalType.'_reversal',
                amount: -(float) $original->amount,
                description: 'عكس الحركة المالية رقم '.$original->id,
                reference: $reference,
                meter: $original->meter,
            );
            if ($original->retired_meter_id) {
                $reversal->forceFill([
                    'retired_meter_id' => $original->retired_meter_id,
                    'retired_meter_code' => $original->retired_meter_code,
                ])->saveQuietly();
            }
            return $reversal;
        }, 3);
    }

    /**
     * Recalculate and update the subscriber's general balance (meter_id is null).
     */
    public function updateSubscriberBalance(Subscriber $subscriber): void
    {
        $totalBalance = Transaction::where('subscriber_id', $subscriber->id)
            ->whereNull('meter_id')
            ->sum('amount');
        
        $subscriber->updateQuietly(['balance' => $totalBalance]);
    }

    /**
     * Recalculate and update the meter's balance cache based on transactions.
     */
    public function updateMeterBalance(\App\Models\Meter $meter): void
    {
        $totalBalance = Transaction::where('meter_id', $meter->id)->sum('amount');
        
        $meter->updateQuietly(['balance' => $totalBalance]);
    }
}
