<?php

namespace App\Filament\Resources\SubscriberResource\RelationManagers;

use App\Filament\Resources\InvoiceResource;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'الفواتير';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('bill_number')
                    ->label('رقم الفاتورة')
                    ->searchable(),

                Tables\Columns\TextColumn::make('bill_date')
                    ->label('التاريخ')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('الإجمالي')
                    ->numeric(2)->suffix(' ريال')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount_paid')
                    ->label('المسدد')
                    ->numeric(2)->suffix(' ريال'),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'مسودة',
                        'issued' => 'صادرة',
                        'partially_paid' => 'مسددة جزئياً',
                        'paid' => 'مسددة',
                        'overdue' => 'متأخرة',
                        'cancelled' => 'ملغاة',
                        default => $state,
                    })
                    ->colors([
                        'gray' => 'draft',
                        'info' => 'issued',
                        'warning' => 'partially_paid',
                        'success' => 'paid',
                        'danger' => 'overdue',
                    ]),
            ])
            ->defaultSort('bill_date', 'desc')
            ->actions([
                \Filament\Actions\Action::make('view_invoice')
                    ->label('عرض')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => InvoiceResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}





