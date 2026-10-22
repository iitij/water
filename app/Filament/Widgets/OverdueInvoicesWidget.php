<?php

namespace App\Filament\Widgets;

use App\Models\Invoice;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class OverdueInvoicesWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '⚠️ فواتير متأخرة';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Invoice::query()
                    ->where('status', 'overdue')
                    ->with('subscriber')
                    ->orderBy('bill_date')
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

                Tables\Columns\TextColumn::make('amount_paid')
                    ->label('المسدد')
                    ->numeric(2)->suffix(' ريال'),
            ])
            ->paginated(false)
            ->emptyStateHeading('لا توجد فواتير متأخرة 🎉')
            ->emptyStateDescription('جميع الفواتير مسددة أو في حالة جيدة');
    }
}





