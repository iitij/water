<?php

namespace App\Filament\Resources\ReadingResource\Pages;

use App\Filament\Resources\ReadingResource;
use App\Models\Meter;
use App\Models\Reading;
use App\Services\CurrentMeterReadingGuard;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateReading extends CreateRecord
{
    protected static string $resource = ReadingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $meter = Meter::findOrFail($data['meter_id']);
        if (! $meter->isCurrent()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'meter_id' => ['لا يمكن تسجيل قراءة جديدة على عداد منزوع.'],
            ]);
        }
        if ((float) ($data['current_reading'] ?? 0) < $meter->getLastReadingValue()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'current_reading' => ['القراءة الحالية أقل من القراءة السابقة.'],
            ]);
        }

        $data['previous_reading'] = $meter->getLastReadingValue();
        $data['actual_reading_date'] = $data['actual_reading_date'] ?? $data['reading_date'];
        $period = app(\App\Services\ReadingService::class)
            ->calculateReadingPeriod($meter->id, $data['actual_reading_date']);
        $data['period_start'] = $period['period_start'];
        $data['period_end'] = $period['period_end'];
        $data['period_basis'] = 'observation';

        return $data;
    }

    /**
     * Re-check under the same meter row lock used by replacement and the API.
     * A user may leave a Filament form open while another device replaces
     * the meter; form pre-validation alone cannot protect that race.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $subscriberId = Meter::findOrFail((int) $data['meter_id'])->subscriber_id;
            \App\Models\Subscriber::query()->lockForUpdate()->findOrFail($subscriberId);
            $meter = Meter::query()->lockForUpdate()->findOrFail((int) $data['meter_id']);
            abort_unless((int) $meter->subscriber_id === (int) $subscriberId, 409);
            if (! $meter->isCurrent()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                        'meter_id' => ['لا يمكن تسجيل قراءة جديدة على عداد منزوع.'],
                ]);
            }
            if ((float) ($data['current_reading'] ?? 0) < $meter->getLastReadingValue()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                        'current_reading' => ['القراءة الحالية أقل من القراءة السابقة.'],
                ]);
            }

            $data['previous_reading'] = $meter->getLastReadingValue();
            $data['actual_reading_date'] = $data['actual_reading_date'] ?? $data['reading_date'];
            $period = app(\App\Services\ReadingService::class)
                ->calculateReadingPeriod($meter->id, $data['actual_reading_date']);
            $data['period_start'] = $period['period_start'];
            $data['period_end'] = $period['period_end'];
            $data['period_basis'] = 'observation';

            app(CurrentMeterReadingGuard::class)->assertCanCreate(
                $meter, $data['current_reading'], $data['period_start'],
                $data['period_end'], $data['actual_reading_date'],
            );
            return Reading::create($data);
        });
    }
}
