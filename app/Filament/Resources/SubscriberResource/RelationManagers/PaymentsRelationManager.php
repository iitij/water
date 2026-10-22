<?php

namespace App\Filament\Resources\SubscriberResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'المدفوعات';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('receipt_number')
                    ->label('رقم سند القبض')
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('المبلغ')
                    ->numeric(2)->suffix(' ريال'),

                Tables\Columns\TextColumn::make('payment_date')
                    ->label('التاريخ')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('طريقة الدفع')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cash' => 'نقدي',
                        'bank_transfer' => 'تحويل بنكي',
                        'check' => 'شيك',
                        default => $state,
                    })
                    ->badge(),

                Tables\Columns\TextColumn::make('invoice.bill_number')
                    ->label('رقم الفاتورة')
                    ->placeholder('غير محدد'),
            ])
            ->defaultSort('payment_date', 'desc');
    }
}





