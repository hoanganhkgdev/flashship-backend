<?php

namespace App\Filament\Widgets;

use App\Filament\Support\BreakdownWidget;

class OrderSourcesWidget extends BreakdownWidget
{
    protected int|string|array $columnSpan = ['default' => 12, 'md' => 6, 'xl' => 4];

    protected static ?int $sort = 6;

    protected function title(): string
    {
        return 'Nguồn đơn';
    }

    protected function description(): string
    {
        return 'Đơn tạo hôm nay theo kênh đặt';
    }

    protected function rows(): array
    {
        $labels = ['customer_app' => 'App khách', 'shop_app' => 'App shop', 'call_center' => 'Tổng đài'];

        return $this->todayOrders()
            ->selectRaw('platform, COUNT(*) AS c')
            ->groupBy('platform')
            ->get()
            ->map(fn ($r) => [
                'label' => $labels[$r->platform] ?? ($r->platform ?: 'Chưa rõ'),
                'count' => (int) $r->c,
            ])
            ->all();
    }
}
