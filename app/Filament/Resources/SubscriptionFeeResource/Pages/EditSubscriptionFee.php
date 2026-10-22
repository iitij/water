<?php

namespace App\Filament\Resources\SubscriptionFeeResource\Pages;

use App\Filament\Resources\SubscriptionFeeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSubscriptionFee extends EditRecord
{
    protected static string $resource = SubscriptionFeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No delete action here to prevent accidental deletion of subscribers
        ];
    }
}
