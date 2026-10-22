<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SquareResource\Pages;
use App\Filament\Resources\SquareResource\RelationManagers\SubscribersRelationManager;
use App\Models\Square;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SquareResource extends Resource
{
    protected static ?string $model = Square::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-map';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المشتركين';

    protected static ?string $modelLabel = 'مربع سكني';

    protected static ?string $pluralModelLabel = 'المربعات السكنية';

    protected static ?int $navigationSort = 1;

    public static function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form->schema([
            \Filament\Schemas\Components\Section::make('بيانات المربع السكني')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('اسم المربع')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('مثال: المربع A'),

                    Forms\Components\Textarea::make('description')
                        ->label('الوصف')
                        ->rows(3)
                        ->placeholder('وصف اختياري للمربع السكني'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('اسم المربع')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('subscribers_count')
                    ->label('عدد المشتركين')
                    ->counts('subscribers')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            SubscribersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSquares::route('/'),
            'create' => Pages\CreateSquare::route('/create'),
            'edit' => Pages\EditSquare::route('/{record}/edit'),
        ];
    }
}





