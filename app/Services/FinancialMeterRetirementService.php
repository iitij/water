<?php

namespace App\Services;

use App\Models\Meter;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class FinancialMeterRetirementService
{
    /**
     * Called under subscriber and meter locks. Preserve posted documents and
     * billed readings, but remove the physical meter row before creating a new one.
     */
    public function retire(Meter $meter, Subscriber $subscriber): int
    {
        // Existing callers must not gain permission to discard pending usage.
        return $this->retireWithIntent($meter, $subscriber, false);
    }

    public function retireForReplacement(Meter $meter, Subscriber $subscriber): int
    {
        return $this->retireWithIntent($meter, $subscriber, true);
    }

    private function retireWithIntent(Meter $meter, Subscriber $subscriber, bool $discardPending): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Financial retirement requires an outer transaction.');
        }
        $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($subscriber->id);
        $meter = Meter::query()->lockForUpdate()->findOrFail($meter->id);
        if (! $meter->isCurrent() || (int) $meter->subscriber_id !== (int) $subscriber->id) {
            throw ValidationException::withMessages(['meter_id' => 'العداد ليس العداد الحالي للمشترك.']);
        }
        foreach (['invoices', 'payments', 'transactions', 'readings'] as $table) {
            if (! Schema::hasColumns($table, ['retired_meter_id', 'retired_meter_code'])) {
                throw ValidationException::withMessages(['meter_id' => 'بنية حفظ الهوية المالية غير متوفرة.']);
            }
        }
        $invoices = DB::table('invoices')->where('meter_id', $meter->id)->orderBy('id')->lockForUpdate()->get();
        $readings = DB::table('readings')->where('meter_id', $meter->id)->orderBy('id')->lockForUpdate()->get();
        $invoiceIds = $invoices->pluck('id')->map(fn ($id) => (int) $id)->all();
        $purger = app(MeterDataPurger::class);
        $pendingIds = $purger->pendingReadingIds($readings);
        if (! $discardPending && $pendingIds !== []) {
            throw ValidationException::withMessages(['meter_id' => 'تتطلب إزالة القراءة غير المفوترة استبدالاً معتمداً.']);
        }
        $protected = $readings->reject(fn ($r) => in_array((int) $r->id, $pendingIds, true));
        if ($invoices->contains(fn ($i) => $i->status === 'draft'
                || (int) $i->subscriber_id !== (int) $subscriber->id
                || $i->retired_meter_id !== null || $i->retired_meter_code !== null)
            || $protected->contains(fn ($r) => ! in_array($r->is_invoiced, [true, 1, '1'], true)
                || ! in_array((int) $r->invoice_id, $invoiceIds, true)
                || $r->retired_meter_id !== null || $r->retired_meter_code !== null)) {
            throw ValidationException::withMessages([
                'meter_id' => 'يجب معالجة المسودات والارتباطات المالية الملتبسة قبل استبدال العداد.',
            ]);
        }
        // A price snapshot must still reference the same original invoice and reading.
        if (Schema::hasTable('invoice_tariff_lines')) {
            $protectedById = $protected->keyBy('id');
            $lines = DB::table('invoice_tariff_lines')
                ->where(fn ($q) => $q->whereIn('reading_id', $readings->pluck('id'))
                    ->orWhereIn('invoice_id', $invoiceIds))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($lines as $line) {
                $reading = $protectedById->get($line->reading_id);
                if (! $reading || (int) $reading->invoice_id !== (int) $line->invoice_id) {
                    throw ValidationException::withMessages(['meter_id' => 'ارتباط التعرفة التاريخية غير متطابق.']);
                }
            }
        }
        // Preserve any conflicting documents rather than silently relinking another account.
        foreach (['payments', 'transactions'] as $table) {
            $documents = DB::table($table)->where('meter_id', $meter->id)->orderBy('id')->lockForUpdate()->get();
            if ($documents->contains(fn ($row) => (int) $row->subscriber_id !== (int) $subscriber->id
                || $row->retired_meter_id !== null || $row->retired_meter_code !== null)) {
                throw ValidationException::withMessages(['meter_id' => 'ارتباط المستند المالي بالعداد غير متطابق.']);
            }
        }
        if ($purger->hasPendingReceiptReviewLocked((int) $meter->id)) {
            throw ValidationException::withMessages(['meter_id' => 'توجد مراجعة قبض معلقة لهذا العداد.']);
        }
        $deleted = DB::table('readings')->where('meter_id', $meter->id)->whereIn('id', $pendingIds)->delete();
        if ($deleted !== count($pendingIds)) {
            throw new \RuntimeException('Obsolete reading set changed during retirement.');
        }
        $purger->scheduleImageCleanup($readings->filter(fn ($r) => in_array((int) $r->id, $pendingIds, true)), (int) $meter->id);

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

        return $deleted;
    }
}
