<?php

namespace App\Filament\Widgets;

use App\Filament\Support\BreakdownWidget;
use Modules\Core\Models\ServiceType;

class ServiceMixWidget extends BreakdownWidget
{
    protected int|string|array $columnSpan = ['default' => 12, 'md' => 6, 'xl' => 4];

    protected static ?int $sort = 5;

    protected function title(): string
    {
        return 'Đơn theo dịch vụ';
    }

    protected function description(): string
    {
        return 'Đơn tạo hôm nay, kèm doanh thu đơn đã hoàn thành';
    }

    protected function rows(): array
    {
        $labels = ServiceType::pluck('label', 'key');

        return $this->todayOrders()
            ->selectRaw("service_type, COUNT(*) AS c, COALESCE(SUM(CASE WHEN status = 'completed' THEN shipping_fee END), 0) AS fee")
            ->groupBy('service_type')
            ->get()
            ->map(fn ($r) => [
                'label' => $labels[$r->service_type] ?? ($r->service_type ?: 'Chưa rõ'),
                'count' => (int) $r->c,
                'note' => number_format((float) $r->fee, 0, ',', '.').'₫',
            ])
            ->all();
    }
}
