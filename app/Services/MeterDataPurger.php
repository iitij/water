<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Permanently removes an obsolete meter and all records belonging to its
 * account. Use only in the explicit, irreversible single-meter cleanup and
 * replacement flows. Does not touch subscriber-wide records without a link
 * to this meter or to an invoice generated for it.
 *
 * Caller should wrap the operation in a transaction together with creation of
 * the successor meter and subscriber-level uniqueness changes.
 */
final class MeterDataPurger
{
    public function purge(int $meterId): void
    {
        $meter = DB::table('meters')->where('id', $meterId)->first();
        if (! $meter) {
            return;
        }

        $invoiceIds = DB::table('invoices')->where('meter_id', $meterId)->pluck('id')->all();
        $paymentIds = DB::table('payments')
            ->where(function ($q) use ($meterId, $invoiceIds): void {
                $q->where('meter_id', $meterId)->orWhereIn('invoice_id', $invoiceIds);
            })
            ->pluck('id')->all();

        // Delete dependent ledger entries BEFORE deleting nullable-FK parents:
        // ON DELETE SET NULL would otherwise disguise where the records came
        // from and leave inaccurate subscriber financial balances.
        DB::table('transactions')
            ->where(function ($q) use ($meterId, $invoiceIds, $paymentIds): void {
                $q->where('meter_id', $meterId)
                    ->orWhere(function ($refs) use ($invoiceIds): void {
                        $refs->where('reference_type', Invoice::class)
                            ->whereIn('reference_id', $invoiceIds);
                    })
                    ->orWhere(function ($refs) use ($paymentIds): void {
                        $refs->where('reference_type', Payment::class)
                            ->whereIn('reference_id', $paymentIds);
                    });
            })->delete();

        DB::table('reading_faults')->where('meter_id', $meterId)->delete();
        DB::table('route_visits')->where('meter_id', $meterId)->delete();
        DB::table('subscription_fees')
            ->where(function ($q) use ($meterId, $invoiceIds): void {
                $q->where('meter_id', $meterId)->orWhereIn('invoice_id', $invoiceIds);
            })->delete();
        DB::table('service_requests')->whereIn('invoice_id', $invoiceIds)->delete();

        DB::table('readings')
            ->where(function ($q) use ($meterId, $invoiceIds): void {
                $q->where('meter_id', $meterId)->orWhereIn('invoice_id', $invoiceIds);
            })->delete();
        DB::table('payments')->whereIn('id', $paymentIds)->delete();
        // invoice_items are removed by ON DELETE CASCADE.
        DB::table('invoices')->whereIn('id', $invoiceIds)->delete();

        DB::table('audit_events')
            ->where(function ($q) use ($meterId, $invoiceIds, $paymentIds): void {
                $q->where(function ($meterQuery) use ($meterId): void {
                    $meterQuery->whereIn('entity_type', ['meter', Meter::class])
                        ->where('entity_id', (string) $meterId);
                });
                foreach ([
                    ['invoice', Invoice::class, $invoiceIds],
                    ['payment', Payment::class, $paymentIds],
                ] as [$alias, $class, $ids]) {
                    if ($ids !== []) {
                        $q->orWhere(function ($eventQuery) use ($alias, $class, $ids): void {
                            $eventQuery->whereIn('entity_type', [$alias, $class])
                                ->whereIn('entity_id', array_map('strval', $ids));
                        });
                    }
                }
            })->delete();

        DB::table('meters')->where('id', $meterId)->delete();

        // General subscriber balance is a cache of general (meter-less) ledger.
        // Never carry a deleted meter's balance into the survivor.
        DB::table('subscribers')->where('id', $meter->subscriber_id)->update([
            'balance' => DB::table('transactions')
                ->where('subscriber_id', $meter->subscriber_id)
                ->whereNull('meter_id')
                ->whereNull('deleted_at')
                ->sum('amount'),
        ]);
    }
}
