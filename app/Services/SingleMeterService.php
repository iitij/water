<?php

namespace App\Services;

use App\Models\Meter;
use App\Models\AuditEvent;
use App\Models\Subscriber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared write policy for API and Filament.
 *
 * Every subscriber has at most ONE persisted meter row. Inactive status
 * does not free that slot. Replacement permanently purges the previous meter.
 *
 * The database UNIQUE(subscriber_id) index is the final concurrency guard;
 * subscriber locks provide user-friendly errors.
 */
class SingleMeterService
{
    public function create(array $data): Meter
    {
        return DB::transaction(function () use ($data): Meter {
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail((int) $data['subscriber_id']);
            $this->ensureAvailable($subscriber->id);
            $this->ensurePathAvailable((int) $subscriber->square_id, $data['path_number'] ?? null);

            $data['status'] ??= 'active';
            $data['removed_at'] = null;

            try {
                return Meter::create($data);
            } catch (QueryException $e) {
                $this->translateConcurrentAssignment($subscriber->id, $e);

                throw $e;
            }
        });
    }

    public function update(Meter $meter, array $data): Meter
    {
        return DB::transaction(function () use ($meter, $data): Meter {
            $oldSubscriberId = (int) $meter->subscriber_id;
            $newSubscriberId = (int) ($data['subscriber_id'] ?? $meter->subscriber_id);

            // Lock both subscriber rows in stable order to prevent concurrent
            // assignments to the same destination.
            $subscribers = Subscriber::query()
                ->whereIn('id', array_unique([$oldSubscriberId, $newSubscriberId]))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $meter = Meter::query()->lockForUpdate()->findOrFail($meter->getKey());
            if ((int) $meter->subscriber_id !== $oldSubscriberId) {
                abort(409, 'تغير مالك العداد؛ حدّث البيانات وأعد المحاولة.');
            }
            $destination = $subscribers->get($newSubscriberId);
            if (! $destination) {
                throw ValidationException::withMessages([
                    'subscriber_id' => ['المشترك المحدد غير موجود.'],
                ]);
            }

            if ($newSubscriberId !== $oldSubscriberId && $this->hasHistory($meter)) {
                throw ValidationException::withMessages([
                    'subscriber_id' => [
                        'لا يمكن نقل عداد له قراءات أو فواتير أو سندات أو حركات مالية '
                        .'عن طريق تعديل المشترك؛ تعالج بياناته المالية أولاً قبل النقل.',
                    ],
                ]);
            }

            $newRemoval = array_key_exists('removed_at', $data)
                ? $data['removed_at']
                : $meter->removed_at;
            if ($newRemoval !== null) {
                throw ValidationException::withMessages([
                    'removed_at' => ['لا يُسمح بالاحتفاظ بعداد منزوع في السجل. استخدم استبدال العداد أو حذفه.'],
                ]);
            }

            if ($newRemoval === null) {
                $this->ensureAvailable($newSubscriberId, $meter->id);
            }

            $pathNumber = array_key_exists('path_number', $data)
                ? $data['path_number']
                : $meter->path_number;
            if ($newRemoval === null) {
                $this->ensurePathAvailable((int) $destination->square_id, $pathNumber, $meter->id);
            }

            try {
                $meter->update($data);
            } catch (QueryException $e) {
                if ($newRemoval === null) {
                    $this->translateConcurrentAssignment($newSubscriberId, $e, $meter->id);
                }

                throw $e;
            }

            return $meter->refresh();
        });
    }

    /**
     * Replace one physical meter without keeping a second, historical record.
     *
     * Linked test readings, invoices, payments and ledger entries of the old
     * meter are permanently erased in the same transaction. The new meter
     * receives a fresh ID so offline requests for the old ID cannot be posted
     * silently to the replacement.
     */
    public function replace(Meter $oldMeter, array $newMeterData, string $removedAt, string $reason, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($oldMeter, $newMeterData, $removedAt, $reason, $actorId): array {
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($oldMeter->subscriber_id);
            $old = Meter::query()->lockForUpdate()->findOrFail($oldMeter->getKey());
            abort_unless((int) $old->subscriber_id === (int) $subscriber->id, 409, 'تغير مالك العداد؛ حدّث البيانات.');

            if (! $old->isCurrent()) {
                throw ValidationException::withMessages([
                    'meter_id' => ['لا يمكن استبدال عداد غير حالي.'],
                ]);
            }
            if (array_key_exists('subscriber_id', $newMeterData)
                && (int) $newMeterData['subscriber_id'] !== (int) $subscriber->id) {
                throw ValidationException::withMessages([
                    'subscriber_id' => ['لا يجوز تغيير المشترك أثناء استبدال عداده.'],
                ]);
            }

            $newMeterData['subscriber_id'] = $subscriber->id;
            $newMeterData['removed_at'] = null;
            $newMeterData['status'] ??= 'active';

            // Validate BEFORE deleting the old meter; rollback is still a final
            // safety net for unique constraints and concurrent requests.
            $this->ensurePathAvailable(
                (int) $subscriber->square_id,
                $newMeterData['path_number'] ?? null,
                $old->id
            );

            app(MeterDataPurger::class)->purge((int) $old->id);
            $replacement = $this->create($newMeterData);

            AuditEvent::create([
                'actor_user_id' => $actorId,
                'event' => 'meter.replaced',
                'entity_type' => 'meter',
                'entity_id' => (string) $replacement->id,
                'before' => null,
                'after' => [
                    'subscriber_id' => $subscriber->id,
                    'reason' => $reason,
                    'old_meter_data_purged' => true,
                ],
                'source' => 'api',
                'occurred_at' => now(),
            ]);

            return [
                'current_meter' => $replacement->load('subscriber.square'),
            ];
        }, 3);
    }

    private function ensureAvailable(int $subscriberId, ?int $exceptMeterId = null): void
    {
        $query = Meter::withTrashed()->where('subscriber_id', $subscriberId);
        if ($exceptMeterId !== null) {
            $query->where('id', '!=', $exceptMeterId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'subscriber_id' => [
                    'هذا المشترك مرتبط بعداد بالفعل. لا يمكن إضافة عداد ثانٍ.',
                ],
            ]);
        }
    }

    private function translateConcurrentAssignment(int $subscriberId, QueryException $exception, ?int $exceptMeterId = null): void
    {
        // Only translate the known uniqueness conflict; preserve other DB errors.
        if (Meter::withTrashed()->where('subscriber_id', $subscriberId)
            ->when($exceptMeterId !== null, fn ($query) => $query->where('id', '!=', $exceptMeterId))
            ->exists()) {
            $this->ensureAvailable($subscriberId, $exceptMeterId);
        }
    }

    private function ensurePathAvailable(int $squareId, mixed $pathNumber, ?int $exceptMeterId = null): void
    {
        if ($pathNumber === null || $pathNumber === '') {
            return;
        }
        $query = Meter::query()
            ->where('path_number', (int) $pathNumber)
            ->whereNull('removed_at')
            ->whereHas('subscriber', fn ($q) => $q->where('square_id', $squareId));
        if ($exceptMeterId !== null) {
            $query->where('id', '!=', $exceptMeterId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'path_number' => ['رقم المسار مستخدم بالفعل لعداد حالي في هذا المربع السكني.'],
            ]);
        }
    }

    private function hasHistory(Meter $meter): bool
    {
        return $meter->readings()->withTrashed()->exists()
            || $meter->payments()->withTrashed()->exists()
            || $meter->transactions()->withTrashed()->exists()
            || $meter->invoices()->withTrashed()->exists();
    }
}
