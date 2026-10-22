<?php

namespace App\Filament\Resources\Tariffs\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TariffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('الاسم')
                    ->required(),
                TextInput::make('unit_price')
                    ->label('سعر الوحدة')
                    ->required()
                    ->numeric()
                    ->prefix('ريال'),
                Toggle::make('is_active')
                    ->label('نشط')
                    ->default(true)
                    ->required(),
                DatePicker::make('start_date')
                    ->label('تاريخ البدء'),
                DatePicker::make('end_date')
                    ->label('تاريخ الانتهاء'),
            ]);
    }
}
