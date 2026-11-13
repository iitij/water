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
 * Current = not removed AND not soft-deleted. "inactive" still occupies the
 * subscriber's meter slot until a recorded replacement/removal.
 *
 * The generated UNIQUE index added by the single-meter migration is the
 * final concurrency guard; subscriber locks provide user-friendly errors.
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
            $meter = Meter::query()->lockForUpdate()->findOrFail($meter->getKey());
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
                        .'عن طريق تعديل المشترك؛ يلزم إجراء نقل مستقل يحفظ التاريخ.',
                    ],
                ]);
            }

            $newRemoval = array_key_exists('removed_at', $data)
                ? $data['removed_at']
                : $meter->removed_at;

            if ($meter->removed_at === null && $newRemoval !== null
                && $meter->readings()->where('is_invoiced', false)->exists()) {
                throw ValidationException::withMessages([
                    'removed_at' => ['لا يمكن إنهاء ارتباط عداد لديه قراءات لم تُفوتر بعد.'],
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
     * Atomically retire one current meter and assign a replacement.
     *
     * This does not move invoices, payments, balances or readings. Outstanding
     * financial obligations remain linked to the historical meter and continue
     * to appear in the subscriber's consolidated statement.
     */
    public function replace(Meter $oldMeter, array $newMeterData, string $removedAt, string $reason, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($oldMeter, $newMeterData, $removedAt, $reason, $actorId): array {
            $old = Meter::query()->lockForUpdate()->findOrFail($oldMeter->getKey());
            $subscriber = Subscriber::query()->lockForUpdate()->findOrFail($old->subscriber_id);

            if (! $old->isCurrent()) {
                throw ValidationException::withMessages([
                    'meter_id' => ['لا يمكن استبدال عداد تاريخي أو محذوف. اختر العداد الحالي.'],
                ]);
            }
            if ($old->readings()->where('is_invoiced', false)->exists()) {
                throw ValidationException::withMessages([
                    'meter_id' => ['توجد قراءات غير مفوترة. أكمل الفوترة وتسوية آخر قراءة قبل الاستبدال.'],
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
            $newMeterData['status'] = $newMeterData['status'] ?? 'active';
            $oldSnapshot = [
                'id' => $old->id,
                'unique_code' => $old->unique_code,
                'subscriber_id' => $subscriber->id,
                'balance' => (string) $old->balance,
                'last_reading' => (string) ($old->readings()->latest('id')->value('current_reading') ?? $old->initial_reading),
            ];

            $old->update(['removed_at' => $removedAt, 'status' => 'inactive']);
            // If creation fails, the outer transaction restores the old meter.
            $replacement = $this->create($newMeterData);

            AuditEvent::create([
                'actor_user_id' => $actorId,
                'event' => 'meter.replaced',
                'entity_type' => 'meter',
                'entity_id' => (string) $old->id,
                'before' => $oldSnapshot,
                'after' => [
                    'replacement_meter_id' => $replacement->id,
                    'subscriber_id' => $subscriber->id,
                    'removed_at' => $removedAt,
                    'reason' => $reason,
                    'old_account_balance_retained' => true,
                ],
                'source' => 'api',
                'occurred_at' => now(),
            ]);

            return [
                'old_meter' => $old->refresh(),
                'current_meter' => $replacement->load('subscriber.square'),
            ];
        }, 3);
    }

    private function ensureAvailable(int $subscriberId, ?int $exceptMeterId = null): void
    {
        $query = Meter::query()->where('subscriber_id', $subscriberId)->whereNull('removed_at');
        if ($exceptMeterId !== null) {
            $query->where('id', '!=', $exceptMeterId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'subscriber_id' => [
                    'هذا المشترك مرتبط بعداد حالي بالفعل. يجب إنهاء ارتباط العداد '
                    .'السابق وفق الإجراءات المعتمدة قبل تسجيل عداد جديد.',
                ],
            ]);
        }
    }

    private function translateConcurrentAssignment(int $subscriberId, QueryException $exception, ?int $exceptMeterId = null): void
    {
        // Only translate the known uniqueness conflict; preserve other DB errors.
        if (Meter::query()->where('subscriber_id', $subscriberId)
            ->whereNull('removed_at')
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
