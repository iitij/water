<?php

namespace App\Services;

use App\Models\Meter;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinancialMeterRetirementService
{
    /**
     * Called under subscriber and meter locks. Preserve posted documents and
     * billed readings, but remove the physical meter row before creating a new one.
     */
    public function retire(Meter $meter, Subscriber $subscriber): void
    {
        $invoices = DB::table('invoices')->where('meter_id', $meter->id)->lockForUpdate()->get();
        $readings = DB::table('readings')->where('meter_id', $meter->id)->lockForUpdate()->get();
        $invoiceIds = $invoices->pluck('id')->all();

        if ($invoices->contains(fn ($i) => $i->status === 'draft')
            || $readings->contains(fn ($r) => ! $r->is_invoiced || ! in_array($r->invoice_id, $invoiceIds))) {
            throw ValidationException::withMessages([
                'meter_id' => 'يجب إنهاء المسودات وفوترة القراءات المعلقة قبل استبدال عداد له سجل مالي.',
            ]);
        }

        $provenance = [
            'retired_meter_id' => $meter->id,
            'retired_meter_code' => $meter->unique_code,
            'meter_id' => null,
        ];
        foreach (['invoices', 'payments', 'transactions', 'readings'] as $name) {
            DB::table($name)->where('meter_id', $meter->id)->update($provenance);
        }

        // Keep accounting documents, reading images, original payment links,
        // audit events and cash session totals. Only operational meter records go.
        DB::table('reading_faults')->where('meter_id', $meter->id)->update(['meter_id' => null]);
        DB::table('route_visits')->where('meter_id', $meter->id)->update(['meter_id' => null]);
        DB::table('meters')->where('id', $meter->id)->delete();
        app(TransactionService::class)->updateSubscriberBalance($subscriber);
    }
}
