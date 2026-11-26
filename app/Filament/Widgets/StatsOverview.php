<?php

namespace App\Filament\Widgets;

use App\Models\Invoice;
use App\Models\Subscriber;
use App\Support\MoneyBoundary;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalSubscribers = Subscriber::where('status', 'active')->count();

        $finance = app(\App\Services\FinancialReportingService::class);
        $balances = $finance->accountBalances();
        $outstandingAmount = $balances['debt'];

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $grossMinor = $finance->grossReceiptMinor($from, $to);
        $monthlyCollection = MoneyBoundary::numericMajorOutput($grossMinor);

        $cash = $finance->movementsFromMinor($from, $to, null, $grossMinor);
        $overdueCount = Invoice::where('status', 'overdue')->count();

        return [
            Stat::make('المشتركون النشطون', $totalSubscribers)
                ->description('إجمالي المشتركين النشطين')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('المبالغ المستحقة', number_format((float) $outstandingAmount, 2) . ' ريال')
                ->description('إجمالي المتأخرات')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),

            Stat::make('أرصدة المشتركين الدائنة', number_format($balances['credit'], 2).' ريال')->color('primary'),

            Stat::make('تحصيل الشهر', number_format((float) $monthlyCollection, 2) . ' ريال')
                ->description('إجمالي سندات القبض السارية قبل الاسترداد')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('المسترد خلال الشهر', number_format($cash['refunds_disbursed'], 2).' ريال')->color('danger'),
            Stat::make('صافي السندات بعد الاسترداد', number_format($cash['net_receipts_after_refunds'], 2).' ريال')->color('primary'),
            Stat::make('فواتير متأخرة', $overdueCount)
                ->description('تحتاج متابعة')
                ->descriptionIcon('heroicon-m-clock')
                ->color($overdueCount > 0 ? 'danger' : 'success'),
        ];
    }
}





