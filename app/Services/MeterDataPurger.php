<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Only explicitly obsolete, financially unlinked readings may be discarded. */
final class MeterDataPurger
{
    public function purge(int $meterId, ?int $subscriberId = null): void
    {
        $this->purgeWithIntent($meterId, 'ordinary', $subscriberId);
    }

    /** Called only inside the transaction that creates the replacement. */
    public function purgeForReplacement(int $meterId, int $subscriberId): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Replacement requires an outer transaction.');
        }

        return $this->purgeWithIntent($meterId, 'replacement', $subscriberId);
    }

    /** Historical migrations supply the survivor chosen by their existing rule. */
    public function purgeLegacyObsolete(int $meterId, ?int $survivorId): void
    {
        $this->purgeWithIntent($meterId, 'legacy', null, $survivorId);
    }

    private function purgeWithIntent(
        int $meterId, string $intent, ?int $subscriberId = null, ?int $survivorId = null
    ): int {
        return DB::transaction(function () use ($meterId, $intent, $subscriberId, $survivorId): int {
            // A nonlocking read may identify the owner, never authorize deletion.
            // Callers already holding locks supply the expected owner explicitly.
            $ownerId = $subscriberId;
            if ($ownerId === null) {
                $candidate = DB::table('meters')->where('id', $meterId)->first(['subscriber_id']);
                if (! $candidate) {
                    return 0;
                }
                $ownerId = (int) $candidate->subscriber_id;
            }
            if (! DB::table('subscribers')->where('id', $ownerId)->lockForUpdate()->first(['id'])) {
                $this->reject('المشترك المرتبط بالعداد غير موجود.');
            }
            $meters = DB::table('meters')->where('subscriber_id', $ownerId)
                ->orderBy('id')->lockForUpdate()->get();
            $locked = $meters->firstWhere('id', $meterId);
            if (! $locked) {
                $this->reject('تغير العداد أو مالكه؛ حدّث البيانات.');
            }
            if ($intent === 'replacement' && ((int) $locked->subscriber_id !== $subscriberId
                || $locked->removed_at !== null || $locked->deleted_at !== null)) {
                $this->reject('العداد المطلوب استبداله ليس العداد الحالي للمشترك.');
            }
            if ($intent === 'legacy') {
                $actualSurvivor = $meters->last(fn ($m) => $m->removed_at === null && $m->deleted_at === null);
                if (($actualSurvivor ? (int) $actualSurvivor->id : null) !== $survivorId
                    || $meterId === $survivorId) {
                    $this->reject('لا يجوز حذف العداد المعتمد للمشترك.');
                }
            }

            return $this->purgeLocked($locked, $intent, $survivorId);
        }, 3);
    }

    /**
     * All ordinary deletion/retirement callers hold subscriber then meter locks.
     * Use a locking current read, not EXISTS on an earlier REPEATABLE READ snapshot.
     * Legacy migration schemas may not yet contain this table.
     */
    public function hasPendingReceiptReviewLocked(int $meterId): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Pending receipt review guard requires a transaction.');
        }

        return Schema::hasTable('late_receipt_reviews')
            && DB::table('late_receipt_reviews')->where('submitted_meter_id', $meterId)
                ->where('status', 'pending')->orderBy('id')->lockForUpdate()->first(['id']) !== null;
    }

    /** Conservative ordinary deletion/owner-change guard, including soft-deleted evidence. */
    public function hasOrdinaryHistoryLocked(int $meterId): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Meter history guard requires a transaction.');
        }
        foreach (['invoices', 'payments', 'transactions', 'readings'] as $table) {
            if (DB::table($table)->where('meter_id', $meterId)->orderBy('id')
                ->lockForUpdate()->first(['id']) !== null) {
                return true;
            }
        }

        return $this->hasPendingReceiptReviewLocked($meterId);
    }

    /** Read-only classification of exact locked rows; compatible with old schemas. */
    public function pendingReadingIds(Collection $readings): array
    {
        $linked = Schema::hasTable('invoice_tariff_lines')
            ? DB::table('invoice_tariff_lines')->whereIn('reading_id', $readings->pluck('id'))
                ->orderBy('id')->lockForUpdate()->get()->pluck('reading_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return $readings->filter(fn ($r) =>
            in_array($r->is_invoiced, [false, 0, '0'], true)
            && $r->invoice_id === null
            && ($r->retired_meter_id ?? null) === null
            && ($r->retired_meter_code ?? null) === null
            && ! in_array((int) $r->id, $linked, true)
        )->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function purgeLocked(object $meter, string $intent, ?int $survivorId): int
    {
        $meterId = (int) $meter->id;
        $invoices = DB::table('invoices')->where('meter_id', $meterId)->orderBy('id')->lockForUpdate()->get();
        $payments = DB::table('payments')->where(function ($q) use ($meterId, $invoices) {
            $q->where('meter_id', $meterId)->orWhereIn('invoice_id', $invoices->pluck('id'));
        })->orderBy('id')->lockForUpdate()->get();
        $transactions = DB::table('transactions')->where('meter_id', $meterId)
            ->orderBy('id')->lockForUpdate()->get();
        if ($invoices->isNotEmpty() || $payments->isNotEmpty() || $transactions->isNotEmpty()
            || $this->hasPendingReceiptReviewLocked($meterId)) {
            $this->reject('لا يجوز حذف عداد مرتبط بمستندات أو حركات مالية أو مراجعة قبض معلقة.');
        }

        $readings = DB::table('readings')->where('meter_id', $meterId)->orderBy('id')->lockForUpdate()->get();
        $pendingIds = $this->pendingReadingIds($readings);
        if (count($pendingIds) !== $readings->count()) {
            $this->reject('لا يجوز حذف قراءة مرتبطة بمستند مالي أو ذات ارتباط ملتبس.');
        }
        if ($intent === 'ordinary' && $readings->isNotEmpty()) {
            $this->reject('لا يجوز حذف قراءة معلقة للعداد الحالي دون استبدال أو إزالة تاريخية معتمدة.');
        }

        DB::table('reading_faults')->where('meter_id', $meterId)->delete();
        DB::table('route_visits')->where('meter_id', $meterId)->delete();
        $deleted = DB::table('readings')->where('meter_id', $meterId)->whereIn('id', $pendingIds)->delete();
        if ($deleted !== count($pendingIds)) {
            throw new \RuntimeException('Obsolete reading set changed during cleanup.');
        }
        // Audit history is not operational reading data; retain the entire chain.
        DB::table('meters')->where('id', $meterId)->delete();

        // No ledger rows changed; do not rewrite the retained meter or subscriber balance caches.
        if ($intent === 'legacy') {
            $audit = AuditEvent::create([
                'event' => 'meter.obsolete_purged', 'entity_type' => 'meter',
                'entity_id' => (string) $meterId,
                'after' => ['subscriber_id' => (int) $meter->subscriber_id,
                    'retained_meter_id' => $survivorId,
                    'reason' => 'one_meter_legacy_cleanup',
                    'discarded_unbilled_readings' => $deleted],
                'source' => 'migration', 'occurred_at' => now(),
            ]);
            if (! $audit->exists || $audit->getKey() === null) {
                throw new \RuntimeException('Required obsolete meter audit was not persisted.');
            }
        }
        $this->scheduleImageCleanup($readings, $meterId);

        return $deleted;
    }

    /** Images are not part of the DB transaction; never remove a surviving reference. */
    public function scheduleImageCleanup(Collection $deletedReadings, int $meterId): void
    {
        $paths = $deletedReadings->pluck('image_path')->filter(static fn ($path) =>
            is_string($path) && str_starts_with($path, 'readings/')
            && ! str_contains($path, '..') && ! str_contains($path, '\\')
        )->unique()->values()->all();
        if ($paths !== []) {
            DB::afterCommit(static function () use ($paths, $meterId): void {
                foreach ($paths as $path) {
                    try {
                        if (! DB::table('readings')->where('image_path', $path)->exists()
                            && ! Storage::disk('public')->delete($path)) {
                            Log::warning('Obsolete meter image cleanup failed', ['meter_id' => $meterId]);
                        }
                    } catch (\Throwable $exception) {
                        Log::warning('Obsolete meter image cleanup failed', [
                            'meter_id' => $meterId, 'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['meter_id' => $message]);
    }
}
