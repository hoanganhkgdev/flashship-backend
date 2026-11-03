<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\DriverMapPage;
use App\Filament\Pages\RevenueReportPage;
use App\Filament\Support\TenantCity;
use App\Services\DriverSupplyService;
use App\Support\OrderMoney;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Modules\Order\Models\Order;

class StatsOverviewWidget extends Widget
{
    use TenantCity;

    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static string $view = 'filament.widgets.stats-overview';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected function getViewData(): array
    {
        $cityId = $this->cityId();
        $start = now()->startOfDay();
        $end = $start->copy()->addDay();
        // So sánh công bằng: hôm qua tính đến đúng giờ này, không phải cả ngày hôm qua.
        $yStart = $start->copy()->subDay();
        $yNow = now()->subDay();
        $orders = fn () => Order::query()->where('city_id', $cityId);

        // Ba truy vấn nhỏ, mỗi truy vấn đi đúng một index (khu vực + created_at / completed_at / status). Gộp chung
        // bằng OR giữa ba cột thì MySQL phải đọc toàn bộ đơn của khu vực mỗi lần trang tự làm mới.
        $created = $orders()->where('created_at', '>=', $yStart)->where('created_at', '<', $end)
            ->selectRaw("
                COALESCE(SUM(created_at >= ?), 0) AS today,
                COALESCE(SUM(created_at >= ? AND status = 'completed'), 0) AS today_completed,
                COALESCE(SUM(created_at >= ? AND status = 'cancelled'), 0) AS today_cancelled,
                COALESCE(SUM(created_at < ? AND created_at <= ?), 0) AS yday
            ", [$start, $start, $start, $start, $yNow])->first();

        // Doanh thu = tiền khách thật sự trả (OrderMoney::COLLECTED), cùng định nghĩa với trang Doanh thu.
        $done = $orders()->where('status', 'completed')->where('completed_at', '>=', $yStart)->where('completed_at', '<', $end)
            ->selectRaw('
                COALESCE(SUM(completed_at >= ?), 0) AS today,
                COALESCE(SUM(CASE WHEN completed_at >= ? THEN '.OrderMoney::COLLECTED.' END), 0) AS revenue,
                COALESCE(SUM(completed_at < ? AND completed_at <= ?), 0) AS yday,
                COALESCE(SUM(CASE WHEN completed_at < ? AND completed_at <= ? THEN '.OrderMoney::COLLECTED.' END), 0) AS revenue_yday
            ', [$start, $start, $start, $yNow, $start, $yNow])->first();

        // Đang chờ/xử lý: tất cả đơn chưa xong, không phân biệt ngày tạo (khớp tab ở trang Đơn hàng).
        $live = $orders()->whereIn('status', ['pending', 'assigned', 'processing'])
            ->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        $totalOrders = (int) $created->today;
        // Tổng đơn / hủy / tỷ lệ cùng tính theo đơn TẠO hôm nay nên tỷ lệ không bao giờ vượt 100%.
        $completionRate = $totalOrders > 0 ? (int) round((int) $created->today_completed / $totalOrders * 100) : 0;
        $cancelledOrders = (int) $created->today_cancelled;
        // Đơn hoàn thành và doanh thu cùng tính theo ngày HOÀN THÀNH.
        $completedOrders = (int) $done->today;
        $revenue = number_format((float) $done->revenue, 0, ',', '.').'₫';
        $trends = [
            'total' => self::trend($totalOrders, (int) $created->yday),
            'completed' => self::trend($completedOrders, (int) $done->yday),
            'revenue' => self::trend((float) $done->revenue, (float) $done->revenue_yday),
        ];
        $waitingOrders = (int) ($live['pending'] ?? 0);
        $assignedOrders = (int) ($live['assigned'] ?? 0);
        $processingOrders = (int) ($live['processing'] ?? 0);
        $pendingOrders = $waitingOrders + $assignedOrders + $processingOrders;

        // Cùng nguồn với khối Nguồn lực tài xế.
        $supply = app(DriverSupplyService::class)->snapshot($cityId);
        $driversReady = $supply['ready'];
        $driversOnline = $supply['online'];
        $driversBusy = $supply['busy1'] + $supply['busy2'];
        $driversDead = $supply['dead'];

        $cityName = Filament::getTenant()?->name ?? '—';
        $revenueUrl = RevenueReportPage::canAccess() ? RevenueReportPage::getUrl() : null;
        $mapUrl = DriverMapPage::canAccess() ? DriverMapPage::getUrl() : null;

        return compact('totalOrders', 'completedOrders', 'pendingOrders', 'waitingOrders', 'assignedOrders', 'processingOrders', 'cancelledOrders', 'revenue', 'completionRate', 'cityName', 'driversReady', 'driversOnline', 'driversBusy', 'driversDead', 'trends', 'revenueUrl', 'mapUrl');
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
