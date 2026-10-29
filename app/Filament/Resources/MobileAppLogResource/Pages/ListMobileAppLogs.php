<?php

namespace App\Filament\Resources\MobileAppLogResource\Pages;

use App\Filament\Concerns\BelongsToMobileDeveloperSettings;
use App\Filament\Resources\MobileAppLogResource;
use Filament\Resources\Pages\ListRecords;

class ListMobileAppLogs extends ListRecords
{
    use BelongsToMobileDeveloperSettings;

    protected static string $resource = MobileAppLogResource::class;
}
