<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class MonthlyRevenueChart extends ChartWidget
{
    protected ?string $heading = 'التحصيل الشهري';

    protected static ?int $sort = 3;

    protected function getData(): array
    {
        $months = collect();
        $data = collect();

        for ($i = 5; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $months->push($date->translatedFormat('M Y'));

            $total = Payment::whereMonth('payment_date', $date->month)
                ->whereYear('payment_date', $date->year)
                ->sum('amount');

            $data->push((float) $total);
        }

        return [
            'datasets' => [
                [
                    'label' => 'التحصيل (ريال)',
                    'data' => $data->toArray(),
                    'backgroundColor' => 'rgba(5, 150, 105, 0.2)',
                    'borderColor' => 'rgb(5, 150, 105)',
                    'borderWidth' => 2,
                    'fill' => true,
                ],
            ],
            'labels' => $months->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}






