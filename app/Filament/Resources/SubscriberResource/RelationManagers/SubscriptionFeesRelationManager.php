<?php

namespace App\Filament\Resources\SubscriberResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

use Filament\Schemas\Schema;

class SubscriptionFeesRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptionFees';

    protected static ?string $title = 'رسوم الاشتراك';

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\Select::make('meter_id')
                    ->label('العداد')
                    ->options(function (RelationManager $livewire) {
                        return \App\Models\Meter::where('subscriber_id', $livewire->ownerRecord->id)->pluck('unique_code', 'id');
                    })
                    ->searchable(),

                Forms\Components\TextInput::make('amount')
                    ->label('رسوم الاشتراك')
                    ->numeric()
                    ->required()
                    ->prefix('ريال'),

                Forms\Components\Select::make('status')
                    ->label('الحالة')
                    ->options([
                        'uninvoiced' => 'غير مفوترة',
                        'invoiced' => 'مفوترة',
                        'partially_paid' => 'مسدد جزئياً',
                        'paid' => 'مسدد',
                    ])
                    ->default('uninvoiced')
                    ->required(),
                    
                Forms\Components\Textarea::make('notes')
                    ->label('ملاحظات')
                    ->nullable(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('meter.unique_code')
                    ->label('العداد')
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('الرسوم')
                    ->numeric(2)
                    ->suffix(' ريال'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('الحالة')
                    ->colors([
                        'danger' => 'uninvoiced',
                        'warning' => 'invoiced',
                        'primary' => 'partially_paid',
                        'success' => 'paid',
                    ])
                    ->formatStateUsing(function ($state) {
                        return match ($state) {
                            'uninvoiced' => 'غير مفوترة',
                            'invoiced' => 'مفوترة',
                            'partially_paid' => 'مسدد جزئياً',
                            'paid' => 'مسدد',
                            default => $state,
                        };
                    }),
                Tables\Columns\TextColumn::make('invoice.bill_number')
                    ->label('رقم الفاتورة')
                    ->url(fn ($record) => $record->invoice_id ? \App\Filament\Resources\InvoiceResource::getUrl('edit', ['record' => $record->invoice_id]) : null)
                    ->color('primary'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make(),
            ])
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
}
