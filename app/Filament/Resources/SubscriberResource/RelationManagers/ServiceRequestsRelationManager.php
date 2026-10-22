<?php

namespace App\Filament\Resources\SubscriberResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ServiceRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceRequests';

    protected static ?string $title = 'طلبات الوايت';

    public function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form->schema([
            Forms\Components\Hidden::make('service_type')
                ->default('water_tanker'),

            Forms\Components\TextInput::make('amount')
                ->label('سعر الوايت')
                ->numeric()
                ->required()
                ->prefix('ريال'),

            Forms\Components\DatePicker::make('service_date')
                ->label('تاريخ الخدمة')
                ->default(now())
                ->required(),

            Forms\Components\Select::make('status')
                ->label('الحالة')
                ->options([
                    'pending' => 'معلق',
                    'completed' => 'مكتمل',
                    'invoiced' => 'مفوتر',
                    'paid' => 'مسدد',
                ])
                ->default('completed')
                ->required(),

            Forms\Components\Textarea::make('notes')
                ->label('ملاحظات')
                ->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('service_date')
                    ->label('التاريخ')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('المبلغ')
                    ->numeric(2)->suffix(' ريال'),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'معلق',
                        'completed' => 'مكتمل',
                        'invoiced' => 'مفوتر',
                        'paid' => 'مسدد',
                        default => $state,
                    })
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'completed',
                        'primary' => 'invoiced',
                        'success' => 'paid',
                    ]),

                Tables\Columns\TextColumn::make('notes')
                    ->label('ملاحظات')
                    ->limit(30),
            ])
            ->defaultSort('service_date', 'desc')
            ->headerActions([
                \Filament\Actions\CreateAction::make(),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ]);
    }
}





