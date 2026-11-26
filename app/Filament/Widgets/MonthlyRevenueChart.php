<?php

namespace App\Filament\Widgets;

use App\Services\FinancialReportingService;
use Filament\Widgets\ChartWidget;

class MonthlyRevenueChart extends ChartWidget
{
    protected ?string $heading = 'التحصيل الشهري';

    protected static ?int $sort = 3;

    protected function getData(): array
    {
        $months = app(FinancialReportingService::class)->monthlyCollections();

        return [
            'datasets' => [
                [
                    'label' => 'التحصيل (ريال)',
                    'data' => array_column($months, 'value'),
                    'backgroundColor' => 'rgba(5, 150, 105, 0.2)',
                    'borderColor' => 'rgb(5, 150, 105)',
                    'borderWidth' => 2,
                    'fill' => true,
                ],
            ],
            'labels' => array_column($months, 'label'),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}






