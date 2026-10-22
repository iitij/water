<?php

namespace App\Filament\Resources\SquareResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SubscribersRelationManager extends RelationManager
{
    protected static string $relationship = 'subscribers';

    protected static ?string $title = 'المشتركون';

    public function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('الاسم')
                ->required()
                ->maxLength(255),

            \Ysfkaya\FilamentPhoneInput\Forms\PhoneInput::make('phone')
                ->label('رقم الهاتف')
                ->defaultCountry('YE')
                ->initialCountry('ye'),

            Forms\Components\Textarea::make('address')
                ->label('العنوان'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('الهاتف')
                    ->extraAttributes(['dir' => 'ltr']),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->colors([
                        'success' => 'active',
                        'warning' => 'suspended',
                        'danger' => 'inactive',
                    ]),

                Tables\Columns\TextColumn::make('balance')
                    ->label('الرصيد الدائن')
                    ->numeric(2)->suffix(' ريال'),
            ])
            ->filters([])
            ->headerActions([
                \Filament\Actions\CreateAction::make(),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ]);
    }
}





