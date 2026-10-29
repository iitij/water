<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\MobileDeveloperSettings;

trait BelongsToMobileDeveloperSettings
{
    public function getBreadcrumbs(): array
    {
        return [
            MobileDeveloperSettings::getUrl() => 'إعدادات مطور الهاتف',
            ...parent::getBreadcrumbs(),
        ];
    }
}
