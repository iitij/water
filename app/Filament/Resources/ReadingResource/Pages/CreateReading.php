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
        app(CurrentMeterReadingGuard::class)->assertCanCreate(
            $meter,
            $data['current_reading'] ?? null,
            $data['period_start'] ?? null,
            $data['period_end'] ?? null,
        );
        $data['previous_reading'] = $meter->getLastReadingValue();

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
            $meter = Meter::query()->lockForUpdate()->findOrFail((int) $data['meter_id']);
            app(CurrentMeterReadingGuard::class)->assertCanCreate(
                $meter,
                $data['current_reading'] ?? null,
                $data['period_start'] ?? null,
                $data['period_end'] ?? null,
            );
            $data['previous_reading'] = $meter->getLastReadingValue();

            return Reading::create($data);
        });
    }
}
