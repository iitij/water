<?php

namespace App\Filament\Resources\SquareResource\Pages;

use App\Filament\Resources\SquareResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSquare extends EditRecord
{
    protected static string $resource = SquareResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}





