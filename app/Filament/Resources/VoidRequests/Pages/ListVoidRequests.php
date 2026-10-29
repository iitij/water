<?php

namespace App\Filament\Resources\VoidRequests\Pages;

use App\Filament\Concerns\BelongsToMobileDeveloperSettings;
use App\Filament\Resources\VoidRequests\VoidRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListVoidRequests extends ListRecords
{
    use BelongsToMobileDeveloperSettings;

    protected static string $resource = VoidRequestResource::class;

    public function getTitle(): string
    {
        return 'اعتماد إلغاء السندات';
    }

    public function getSubheading(): ?string
    {
        return 'السندات التي تجاوزت حدّ الإلغاء. لا يُلغى سند قبل قرار مشرف، ولا يُحذف أي سند أبداً.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
