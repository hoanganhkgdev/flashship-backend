<?php

namespace App\Filament\Widgets;

use App\Filament\Support\TenantCity;
use Filament\Widgets\ChartWidget;
use Modules\Order\Models\Order;

class OrdersByHourWidget extends ChartWidget
{
    use TenantCity;

    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static ?string $heading = 'Đơn hàng theo giờ hôm nay';

    protected static ?string $description = 'Đơn khách đặt và đơn giao xong trong từng giờ';

    protected static ?string $pollingInterval = '60s';

    protected static ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = ['default' => 12, 'xl' => 8];

    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected function getData(): array
    {
        $cityId = $this->cityId();
        $start = now()->startOfDay();
        $end = $start->copy()->addDay();

        // Hai cột đếm hai việc khác nhau trong cùng một giờ: đơn được tạo, và đơn được giao xong (theo completed_at).
        // Trước đây "Hoàn thành" là đơn TẠO trong giờ đó nay đã xong, nên giờ cao điểm giao hàng không thấy được.
        $created = Order::query()->where('city_id', $cityId)->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->selectRaw('HOUR(created_at) AS h, COUNT(*) AS c')->groupBy('h')->pluck('c', 'h');
        $done = Order::query()->where('city_id', $cityId)->where('status', 'completed')
            ->where('completed_at', '>=', $start)->where('completed_at', '<', $end)
            ->selectRaw('HOUR(completed_at) AS h, COUNT(*) AS c')->groupBy('h')->pluck('c', 'h');

        $byHour = array_fill(0, 24, 0);
        $completed = array_fill(0, 24, 0);
        foreach ($created as $h => $c) {
            $byHour[(int) $h] = (int) $c;
        }
        foreach ($done as $h => $c) {
            $completed[(int) $h] = (int) $c;
        }

        $currentHour = (int) now()->format('H');
        $labels = array_map(fn ($h) => str_pad($h, 2, '0', STR_PAD_LEFT).'h', range(0, $currentHour));

        return [
            'datasets' => [
                [
                    'label' => 'Đơn đặt',
                    'data' => array_slice($byHour, 0, $currentHour + 1),
                    'backgroundColor' => 'rgba(249,115,22,0.7)',
                    'borderColor' => '#f97316',
                    'borderWidth' => 0,
                    'borderRadius' => 8,
                ],
                [
                    'label' => 'Giao xong',
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
