<?php

namespace App\Filament\Resources;

use App\Models\MobileAppLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Support\Icons\Heroicon;

class MobileAppLogResource extends Resource
{
    protected static ?string $model = MobileAppLog::class;
    protected static ?string $modelLabel = 'سجل تطبيق الهاتف';
    protected static ?string $pluralModelLabel = 'سجلات تطبيق الهاتف';
    protected static ?string $navigationLabel = 'Mobile App Logs';
    protected static ?int $navigationSort = 13;
    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() === true;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('الاستلام')->dateTime()->sortable(),
            TextColumn::make('occurred_at')->label('الحدوث')->dateTime()->sortable(),
            TextColumn::make('level')->label('المستوى')->badge()->sortable(),
            TextColumn::make('source')->label('المصدر')->searchable(),
            TextColumn::make('user_id')->label('المستخدم')->sortable(),
            TextColumn::make('device_id')->label('الجهاز')->searchable(),
            TextColumn::make('message')->label('الرسالة')->wrap()->searchable(),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => \App\Filament\Resources\MobileAppLogResource\Pages\ListMobileAppLogs::route('/')];
    }
}
