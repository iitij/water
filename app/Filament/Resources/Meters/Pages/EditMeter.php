<?php

namespace App\Filament\Resources\Meters\Pages;

use App\Filament\Resources\Meters\MeterResource;
use App\Services\SingleMeterService;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\Pages\EditRecord;

class EditMeter extends EditRecord
{
    protected static string $resource = MeterResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(SingleMeterService::class)->update($record, $data);
    }
}
