<?php

namespace App\Filament\Resources\SquareResource\Pages;

use App\Filament\Resources\SquareResource;
use Filament\Resources\Pages\ListRecords;

class ListSquares extends ListRecords
{
    protected static string $resource = SquareResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }
}





