<?php

namespace App\Filament\Resources\Settings;

use App\Filament\Resources\Settings\Pages\ManageSettings;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SettingResource extends Resource
{
    protected static ?string $model = Setting::class;

    protected static ?string $modelLabel = 'إعداد';
    protected static ?string $pluralModelLabel = 'الإعدادات';
    protected static ?string $navigationLabel = 'الإعدادات';
    protected static ?int $navigationSort = 10;
    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $recordTitleAttribute = 'key';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\TextInput::make('key')
                    ->label('المفتاح البرمجي (Key)')
                    ->required(),
                \Filament\Forms\Components\TextInput::make('label')
                    ->label('اسم الإعداد')
                    ->required(),
                \Filament\Forms\Components\Select::make('type')
                    ->label('نوع الإعداد')
                    ->options([
                        'text' => 'نص',
                        'image' => 'صورة',
                    ])
                    ->default('text')
                    ->required()
                    ->live(),
                \Filament\Forms\Components\Textarea::make('value')
                    ->label('القيمة النصية')
                    ->columnSpanFull()
                    ->visible(fn ($get) => $get('type') === 'text'),
                \Filament\Forms\Components\FileUpload::make('image_path')
                    ->label('الصورة')
                    ->image()
                    ->disk('public')
                    ->directory('settings')
                    ->columnSpanFull()
                    ->visible(fn ($get) => $get('type') === 'image'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('key')
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('label')
                    ->label('اسم الإعداد')
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->colors([
                        'primary' => 'text',
                        'success' => 'image',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'text' => 'نص',
                        'image' => 'صورة',
                        default => $state,
                    }),
                \Filament\Tables\Columns\TextColumn::make('value')
                    ->label('القيمة')
                    ->limit(50)
                    ->searchable()
                    ->formatStateUsing(function ($record) {
                        if ($record->type === 'image' && $record->image_path) {
                            return 'صورة مرفوعة';
                        }
                        return $record->value;
                    }),
                \Filament\Tables\Columns\ImageColumn::make('image_path')
                    ->label('معاينة الصورة')
                    ->square(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSettings::route('/'),
        ];
    }
}
