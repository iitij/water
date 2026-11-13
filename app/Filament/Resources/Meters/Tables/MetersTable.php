<?php

namespace App\Filament\Resources\Meters\Tables;

use Filament\Actions\EditAction;
use Filament\Tables;
use Filament\Tables\Table;

class MetersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('unique_code')
                    ->label('رقم العداد')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('path_number')
                    ->label('المسار')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subscriber.name')
                    ->label('المشترك')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subscriber.square.name')
                    ->label('المربع')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'نشط',
                        'inactive' => 'غير نشط',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('current_only')
                    ->label('العداد الحالي')
                    ->default(true)
                    ->queries(
                        true: fn (\Illuminate\Database\Eloquent\Builder $query) => $query->whereNull('removed_at'),
                        false: fn (\Illuminate\Database\Eloquent\Builder $query) => $query->whereNotNull('removed_at'),
                    ),
                Tables\Filters\SelectFilter::make('status')
                    ->label('حالة العداد')
                    ->options([
                        'active' => 'نشط',
                        'inactive' => 'غير نشط',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
