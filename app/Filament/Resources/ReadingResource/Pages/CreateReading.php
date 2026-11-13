<?php
namespace App\Filament\Resources\ReadingResource\Pages;
use App\Filament\Resources\ReadingResource;
use Filament\Resources\Pages\CreateRecord;
class CreateReading extends CreateRecord
{
    protected static string $resource = ReadingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $meter = \App\Models\Meter::findOrFail($data['meter_id']);
        app(\App\Services\CurrentMeterReadingGuard::class)->assertCanCreate(
            $meter,
            $data['current_reading'] ?? null,
            $data['period_start'] ?? null,
            $data['period_end'] ?? null,
        );
        $data['previous_reading'] = $meter->getLastReadingValue();

        return $data;
    }
}





