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
