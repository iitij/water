<?php

namespace App\Filament\Widgets;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalSubscribers = Subscriber::where('status', 'active')->count();

        $outstandingAmount = Invoice::whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->selectRaw('COALESCE(SUM(total_amount - amount_paid), 0) as total')
            ->value('total');

        $monthlyCollection = Payment::whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->sum('amount');

        $overdueCount = Invoice::where('status', 'overdue')->count();

        return [
            Stat::make('المشتركون النشطون', $totalSubscribers)
                ->description('إجمالي المشتركين النشطين')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->chart([7, 3, 4, 5, 6, 3, 5]),

            Stat::make('المبالغ المستحقة', number_format((float) $outstandingAmount, 2) . ' ريال')
                ->description('إجمالي المتأخرات')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),

            Stat::make('تحصيل الشهر', number_format((float) $monthlyCollection, 2) . ' ريال')
                ->description('إجمالي التحصيل هذا الشهر')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->chart([3, 5, 7, 4, 6, 8, 5]),

            Stat::make('فواتير متأخرة', $overdueCount)
                ->description('تحتاج متابعة')
                ->descriptionIcon('heroicon-m-clock')
                ->color($overdueCount > 0 ? 'danger' : 'success'),
        ];
    }
}





