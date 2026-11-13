<?php

namespace App\Filament\Resources\SubscriberResource\RelationManagers;

use App\Models\Meter;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class MetersRelationManager extends RelationManager
{
    protected static string $relationship = 'meters';

    protected static ?string $title = 'العداد المرتبط بالمشترك';

    public function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('unique_code')
                ->label('رقم العداد')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(50),
                
            Forms\Components\Select::make('status')
                ->label('الحالة')
                ->options([
                    'active' => 'نشط',
                    'inactive' => 'غير نشط',
                ])
                ->default('active')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('unique_code')
                    ->label('رقم العداد')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'نشط',
                        'inactive' => 'غير نشط',
                        default => $state,
                    })
                    ->colors([
                        'success' => 'active',
                        'danger' => 'inactive',
                    ]),

                Tables\Columns\TextColumn::make('readings_count')
                    ->label('القراءات')
                    ->counts('readings'),
            ])
            ->headerActions([])
            ->actions([
                // Use the canonical ReadingResource form, which validates
                // reading periods, previous values and invoice options.
                \Filament\Actions\Action::make('record_reading')
                    ->label('تسجيل قراءة')
                    ->icon('heroicon-o-eye')
                    ->color('primary')
                    ->visible(fn (Meter $record): bool => $record->isCurrent())
                    ->url(fn (Meter $record): string =>
                        \App\Filament\Resources\ReadingResource::getUrl('create', [
                            'meter_id' => $record->id,
                        ])),

                \Filament\Actions\EditAction::make(),
            ]);
    }
}





