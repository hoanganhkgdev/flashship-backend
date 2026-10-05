<?php

namespace App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Modules\Order\Models\Order;

class OrdersByHourWidget extends ChartWidget
{
    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static ?string $heading = 'Đơn hàng theo giờ hôm nay';

    protected static ?string $description = 'So sánh tổng đơn phát sinh và số đơn đã hoàn thành';

    protected static ?string $pollingInterval = '60s';

    protected static ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = ['default' => 12, 'xl' => 6];

    protected static bool $isLazy = false;

    protected static ?int $sort = 9;

    protected function getData(): array
    {
        $cityId = Filament::getTenant()?->id;

        $start = now()->startOfDay()->toDateTimeString();
        $end = now()->endOfDay()->toDateTimeString();

        // Đếm theo giờ ngay trong SQL thay vì tải toàn bộ đơn hôm nay vào bộ nhớ.
        $rows = Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->selectRaw("HOUR(created_at) AS h, COUNT(*) AS total, COALESCE(SUM(status = 'completed'), 0) AS completed")
            ->groupBy('h')
            ->get()
            ->keyBy('h');

        $byHour = array_fill(0, 24, 0);
        $completed = array_fill(0, 24, 0);

        foreach ($rows as $h => $row) {
            $byHour[(int) $h] = (int) $row->total;
            $completed[(int) $h] = (int) $row->completed;
        }

        $currentHour = (int) now()->format('H');
        $labels = array_map(fn ($h) => str_pad($h, 2, '0', STR_PAD_LEFT).'h', range(0, $currentHour));

        return [
            'datasets' => [
                [
                    'label' => 'Tổng đơn',
                    'data' => array_slice($byHour, 0, $currentHour + 1),
                    'backgroundColor' => 'rgba(249,115,22,0.7)',
                    'borderColor' => '#f97316',
                    'borderWidth' => 0,
                    'borderRadius' => 8,
                ],
                [
                    'label' => 'Hoàn thành',
                    'data' => array_slice($completed, 0, $currentHour + 1),
                    'backgroundColor' => 'rgba(34,197,94,0.7)',
                    'borderColor' => '#22c55e',
                    'borderWidth' => 0,
                    'borderRadius' => 8,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'top',
                    'align' => 'end',
                    'labels' => ['usePointStyle' => true, 'boxWidth' => 8, 'boxHeight' => 8],
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['stepSize' => 1],
                    'grid' => ['color' => 'rgba(148,163,184,0.12)'],
                ],
                'x' => ['grid' => ['display' => false]],
            ],
        ];
    }
}
