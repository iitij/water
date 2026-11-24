<?php

namespace App\Services;

use App\Models\Meter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently removes an obsolete meter only when it has no financial
 * documents or billed readings. Financial meter replacement is handled by
 * FinancialMeterRetirementService.
 *
 * Caller should wrap the operation in a transaction together with creation of
 * the successor meter and subscriber-level uniqueness changes.
 */
final class MeterDataPurger
{
    public function purge(int $meterId): void
    {
        DB::transaction(function () use ($meterId): void {
            $candidate = DB::table('meters')->where('id', $meterId)->first();
            if (! $candidate) {
                return;
            }
            DB::table('subscribers')->where('id', $candidate->subscriber_id)->lockForUpdate()->first();
            $locked = DB::table('meters')->where('id', $meterId)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }
            abort_unless((int) $locked->subscriber_id === (int) $candidate->subscriber_id, 409, 'تغير مالك العداد.');
            $this->purgeLocked($meterId);
        }, 3);
    }

    private function purgeLocked(int $meterId): void
    {
        $meter = DB::table('meters')->where('id', $meterId)->first();
        if (! $meter) {
            return;
        }

        $invoiceIds = DB::table('invoices')->where('meter_id', $meterId)->pluck('id')->all();
        // Collect the images for EVERY reading being deleted, including rare
        // legacy rows attached to a purged invoice but missing meter_id.
        // This must mirror the reading deletion predicate below.
        $imagePaths = DB::table('readings')
            ->where(function ($q) use ($meterId, $invoiceIds): void {
                $q->where('meter_id', $meterId)->orWhereIn('invoice_id', $invoiceIds);
            })
            ->whereNotNull('image_path')
            ->pluck('image_path')->all();
        $paymentIds = DB::table('payments')
            ->where(function ($q) use ($meterId, $invoiceIds): void {
                $q->where('meter_id', $meterId)->orWhereIn('invoice_id', $invoiceIds);
            })
            ->pluck('id')->all();

        $hasFinancialHistory = $invoiceIds !== [] || $paymentIds !== []
            || DB::table('transactions')->where('meter_id', $meterId)->exists();
        if ($hasFinancialHistory) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'meter_id' => 'لا يجوز حذف عداد مرتبط بمستندات أو حركات مالية. يلزم استبدال يحفظ السجل المحاسبي.',
            ]);
        }

        // Only unbilled operational data can be removed here. No financial
        // document, ledger entry or till settlement can be changed by this path.
        if (DB::table('readings')->where('meter_id', $meterId)
            ->where(fn ($q) => $q->where('is_invoiced', true)->orWhereNotNull('invoice_id'))->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'meter_id' => 'لا يجوز حذف قراءة مرتبطة بمستند مالي.',
            ]);
        }
        // A recorded but unbilled observation is still measured consumption.
        // Replacing a physical meter must never silently discard it, even
        // when the subscriber has not yet received a financial document.
        if (DB::table('readings')->where('meter_id', $meterId)->whereNull('deleted_at')->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'meter_id' => 'يجب فوترة القراءات المعلقة أو تصحيحها صراحةً قبل حذف العداد أو استبداله.',
            ]);
        }
        DB::table('reading_faults')->where('meter_id', $meterId)->delete();
        DB::table('route_visits')->where('meter_id', $meterId)->delete();
        DB::table('readings')->where('meter_id', $meterId)->delete();
        DB::table('audit_events')->whereIn('entity_type', ['meter', Meter::class])
            ->where('entity_id', (string) $meterId)->delete();

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

        foreach (DB::table('meters')->where('subscriber_id', $meter->subscriber_id)->pluck('id') as $remainingId) {
            DB::table('meters')->where('id', $remainingId)->update([
                'balance' => DB::table('transactions')
                    ->where('meter_id', $remainingId)
                    ->whereNull('deleted_at')
                    ->sum('amount'),
            ]);
        }

        // The database deletion is transactional; only delete owned image
        // assets after its commit so a failed replacement keeps the files.
        // Never delete absolute/foreign paths from legacy test fixtures.
        $ownedImages = array_values(array_unique(array_filter(
            $imagePaths,
            static fn ($path) => is_string($path)
                && str_starts_with($path, 'readings/')
                && ! str_contains($path, '..')
        )));
        if ($ownedImages !== []) {
            DB::afterCommit(static function () use ($ownedImages, $meterId): void {
                try {
                    Storage::disk('public')->delete($ownedImages);
                } catch (\Throwable $exception) {
                    Log::warning('Obsolete meter image cleanup failed', [
                        'meter_id' => $meterId,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
        }
    }
}
