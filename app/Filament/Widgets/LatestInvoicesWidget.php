<?php

namespace App\Filament\Widgets;

use App\Models\Invoice;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestInvoicesWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'أحدث الفواتير';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Invoice::query()
                    ->with('subscriber')
                    ->latest('bill_date')
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('bill_number')
                    ->label('رقم الفاتورة'),

                Tables\Columns\TextColumn::make('subscriber.name')
                    ->label('المشترك'),

                Tables\Columns\TextColumn::make('bill_date')
                    ->label('التاريخ')
                    ->date('Y-m-d'),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('الإجمالي')
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
            ->paginated(false);
    }
}





