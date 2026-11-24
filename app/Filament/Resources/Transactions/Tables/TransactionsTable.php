<?php

namespace App\Filament\Resources\Transactions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('subscriber.name')
                    ->label('المشترك')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('نوع الحركة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'payment' => 'success',
                        'invoice' => 'danger',
                        'adjustment' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'payment' => 'سند قبض (دائن)',
                        'invoice' => 'فاتورة (مدين)',
                        'adjustment' => 'تسوية',
                        'payment_reversal' => 'عكس سند قبض',
                        'invoice_reversal' => 'عكس فاتورة',
                        'payment_transfer_in' => 'تصحيح تخصيص وارد',
                        'payment_refund' => 'استرداد رصيد دائن',
                        default => $state,
                    })
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->numeric(2)->suffix(' ريال')
                    ->color(fn ($record) => $record->amount > 0 ? 'success' : 'danger')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('البيان')
                    ->searchable(),
                TextColumn::make('reference_type')
                    ->label('نوع المرجع')
                    ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reference_id')
                    ->label('رقم المرجع')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                //
            ])
            ->defaultSort('created_at', 'desc');
    }
}
