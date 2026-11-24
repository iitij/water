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
                    ->numeric()->minValue(0)->maxValue(99999999.99)->step(0.01)
                    ->prefix('ريال'),
                Toggle::make('is_active')
                    ->label('معتمدة لفترتها')
                    ->helperText('اعتماد تعرفة جديدة لا يلغي التعرفات التاريخية.')
                    ->default(true)
                    ->required(),
                DatePicker::make('start_date')
                    ->label('تاريخ البدء'),
                DatePicker::make('end_date')
                    ->label('تاريخ الانتهاء'),
            ]);
    }
}
