<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\WithdrawRequestResource;
use App\Filament\Pages\DriverMapPage;
use App\Filament\Support\TenantCity;
use App\Services\DriverSupplyService;
use Filament\Facades\Filament;
use Carbon\Carbon;
use Filament\Widgets\Widget;
use Modules\Driver\Models\WithdrawRequest;
use Modules\Order\Models\Order;

/**
 * Những việc cần người vận hành xử lý ngay: đơn chưa có tài xế, đơn không tìm được
 * tài xế, yêu cầu rút tiền chờ duyệt.
 */
class OpsAlertsWidget extends Widget
{
    use TenantCity;

    /** Đơn chờ tài xế quá số phút này thì cảnh báo đỏ (dùng chung với khối Đơn cần theo dõi). */
    public const PENDING_WARN_MINUTES = 5;

    /** Yêu cầu rút tiền chờ quá số giờ này thì cảnh báo đỏ. */
    private const WITHDRAW_WARN_HOURS = 24;

    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static string $view = 'filament.widgets.ops-alerts';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    protected function getViewData(): array
    {
        $cityId = $this->cityId();
        $alerts = [];

        // Đơn chưa có tài xế (mọi ngày), kèm thời gian chờ của đơn cũ nhất.
        $pending = Order::query()
            ->where('city_id', $cityId)
            ->where('status', 'pending')
            ->selectRaw('COUNT(*) AS c, MIN(created_at) AS oldest')
            ->first();
        $pendingCount = (int) $pending->c;
        $waitMinutes = $pendingCount ? (int) Carbon::parse($pending->oldest)->diffInMinutes(now()) : 0;
        $alerts[] = [
            'label' => 'Đơn chưa có tài xế',
            'count' => $pendingCount,
            'detail' => $pendingCount ? 'Đơn cũ nhất đã chờ '.OrderResource::minutesLabel($waitMinutes) : 'Không có đơn nào đang chờ',
            'level' => $pendingCount === 0 ? 'ok' : ($waitMinutes >= self::PENDING_WARN_MINUTES ? 'danger' : 'warn'),
            'url' => OrderResource::getUrl('index', ['activeTab' => 'new']),
            'icon' => 'heroicon-o-clock',
        ];

        $supply = app(DriverSupplyService::class)->snapshot($cityId);
        $dead = (int) $supply['dead'];
        $alerts[] = [
            'label' => 'Tài xế mất kết nối',
            'count' => $dead,
            'detail' => $dead ? "{$dead} tài xế online nhưng GPS đã cũ" : 'Tất cả tài xế online đang có tín hiệu',
            'level' => $dead === 0 ? 'ok' : ($dead >= 3 ? 'danger' : 'warn'),
            // Kế toán không vào được Bản đồ: vẫn thấy số liệu nhưng không có link dẫn tới trang lỗi.
            'url' => DriverMapPage::canAccess() ? DriverMapPage::getUrl() : null,
            'icon' => 'heroicon-o-signal-slash',
        ];

        // Yêu cầu rút tiền chờ duyệt (chỉ hiện với người có quyền xem).
        if (WithdrawRequestResource::canViewAny()) {
            $withdraw = WithdrawRequestResource::scopeEloquentQueryToTenant(WithdrawRequest::query(), Filament::getTenant())
                ->where('status', 'pending')
                ->selectRaw('COUNT(*) AS c, MIN(created_at) AS oldest')
                ->first();
            $withdrawCount = (int) $withdraw->c;
            $waitMinutes = $withdrawCount ? (int) Carbon::parse($withdraw->oldest)->diffInMinutes(now()) : 0;
            $waitHours = intdiv($waitMinutes, 60);
            $alerts[] = [
                'label' => 'Yêu cầu rút tiền chờ duyệt',
                'count' => $withdrawCount,
                'detail' => $withdrawCount ? 'Yêu cầu cũ nhất đã chờ '.OrderResource::minutesLabel($waitMinutes) : 'Không có yêu cầu nào chờ',
                'level' => $withdrawCount === 0 ? 'ok' : ($waitHours >= self::WITHDRAW_WARN_HOURS ? 'danger' : 'warn'),
                'url' => WithdrawRequestResource::getUrl('index'),
                'icon' => 'heroicon-o-banknotes',
            ];
        }

        $needsAttention = collect($alerts)->where('level', '!=', 'ok')->count();

        return compact('alerts', 'needsAttention');
    }
}
