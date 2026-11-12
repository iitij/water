<?php

namespace App\Filament\Resources\Meters\Pages;

use App\Filament\Resources\Meters\MeterResource;
use Filament\Resources\Pages\CreateRecord;
use App\Services\SingleMeterService;
use Illuminate\Database\Eloquent\Model;

class CreateMeter extends CreateRecord
{
    protected static string $resource = MeterResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(SingleMeterService::class)->create($data);
    }
}
