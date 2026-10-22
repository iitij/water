<?php

namespace App\Filament\Resources\Meters;

use App\Filament\Resources\Meters\Pages\CreateMeter;
use App\Filament\Resources\Meters\Pages\EditMeter;
use App\Filament\Resources\Meters\Pages\ListMeters;
use App\Filament\Resources\Meters\Schemas\MeterForm;
use App\Filament\Resources\Meters\Tables\MetersTable;
use App\Models\Meter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MeterResource extends Resource
{
    protected static ?string $model = Meter::class;

    protected static ?string $modelLabel = 'عداد';
    protected static ?string $pluralModelLabel = 'العدادات';
    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المشتركين';
    protected static ?string $navigationLabel = 'إدارة العدادات';
    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'unique_code';

    public static function form(Schema $schema): Schema
    {
        return MeterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MetersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMeters::route('/'),
            'create' => CreateMeter::route('/create'),
            'edit' => EditMeter::route('/{record}/edit'),
        ];
    }
}
