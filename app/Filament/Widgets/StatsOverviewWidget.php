<?php

namespace App\Filament\Widgets;

use App\Services\DriverSupplyService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Modules\Order\Models\Order;

class StatsOverviewWidget extends Widget
{
    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static string $view = 'filament.widgets.stats-overview';

    protected static ?string $pollingInterval = '15s';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected function getViewData(): array
    {
        $cityId = Filament::getTenant()?->id;
        $start = now()->startOfDay()->toDateTimeString();
        $end = now()->endOfDay()->toDateTimeString();
        $active = ['pending', 'assigned', 'processing'];
        // So sánh công bằng: hôm qua tính đến đúng giờ này, không phải cả ngày hôm qua.
        $yStart = now()->subDay()->startOfDay()->toDateTimeString();
        $yNow = now()->subDay()->toDateTimeString();

        // Một truy vấn tổng hợp thay cho 6 truy vấn đếm riêng. Dùng khoảng thời gian
        // (không dùng whereDate) để index created_at còn tác dụng.
        $row = Order::query()
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->where(fn ($q) => $q
                ->where('created_at', '>=', $yStart)
                ->orWhere('completed_at', '>=', $yStart)
                ->orWhereIn('status', $active))
            ->selectRaw("
                COALESCE(SUM(created_at BETWEEN ? AND ?), 0) AS created_today,
                COALESCE(SUM(created_at BETWEEN ? AND ? AND status = 'completed'), 0) AS created_completed,
                COALESCE(SUM(created_at BETWEEN ? AND ? AND status = 'cancelled'), 0) AS created_cancelled,
                COALESCE(SUM(status = 'completed' AND completed_at BETWEEN ? AND ?), 0) AS completed_today,
                COALESCE(SUM(CASE WHEN status = 'completed' AND completed_at BETWEEN ? AND ? THEN shipping_fee END), 0) AS revenue,
                COALESCE(SUM(status IN ('pending', 'assigned', 'processing')), 0) AS active_orders,
                COALESCE(SUM(created_at BETWEEN ? AND ?), 0) AS created_yday,
                COALESCE(SUM(status = 'completed' AND completed_at BETWEEN ? AND ?), 0) AS completed_yday,
                COALESCE(SUM(CASE WHEN status = 'completed' AND completed_at BETWEEN ? AND ? THEN shipping_fee END), 0) AS revenue_yday
            ", array_merge(...array_fill(0, 5, [$start, $end]), ...array_fill(0, 3, [$yStart, $yNow])))
            ->first();

        $totalOrders = (int) $row->created_today;
        // Tổng đơn / hủy / tỷ lệ cùng tính theo đơn TẠO hôm nay nên tỷ lệ không bao giờ vượt 100%.
        $completionRate = $totalOrders > 0 ? (int) round((int) $row->created_completed / $totalOrders * 100) : 0;
        $cancelledOrders = (int) $row->created_cancelled;
        // Đơn hoàn thành và doanh thu cùng tính theo ngày HOÀN THÀNH.
        $completedOrders = (int) $row->completed_today;
        $revenue = number_format((float) $row->revenue, 0, ',', '.').'₫';
        $trends = [
            'total' => self::trend($totalOrders, (int) $row->created_yday),
            'completed' => self::trend($completedOrders, (int) $row->completed_yday),
            'revenue' => self::trend((float) $row->revenue, (float) $row->revenue_yday),
        ];
        // Đang chờ/xử lý: tất cả đơn chưa xong, không phân biệt ngày tạo (khớp tab ở trang Đơn hàng).
        $pendingOrders = (int) $row->active_orders;

        // Cùng nguồn với trang Theo dõi phát đơn.
        $supply = app(DriverSupplyService::class)->snapshot($cityId);
        $driversReady = $supply['ready'];
        $driversOnline = $supply['online'];
        $driversBusy = $supply['busy1'] + $supply['busy2'] + $supply['holding'];
        $driversDead = $supply['dead'];

        $cityName = Filament::getTenant()?->name ?? 'Tất cả khu vực';

        return compact('totalOrders', 'completedOrders', 'pendingOrders', 'cancelledOrders', 'revenue', 'completionRate', 'cityName', 'driversReady', 'driversOnline', 'driversBusy', 'driversDead', 'trends');
    }

    /** @return array{dir: string, text: string} */
    private static function trend(float|int $now, float|int $before): array
    {
        if ($before <= 0) {
            return $now > 0 ? ['dir' => 'up', 'text' => '▲ mới'] : ['dir' => 'flat', 'text' => '— chưa có dữ liệu'];
        }

        $pct = (int) round(($now - $before) / $before * 100);

        return match (true) {
            $pct > 0 => ['dir' => 'up', 'text' => '▲ '.$pct.'%'],
            $pct < 0 => ['dir' => 'down', 'text' => '▼ '.abs($pct).'%'],
            default => ['dir' => 'flat', 'text' => '= 0%'],
        };
    }
}
