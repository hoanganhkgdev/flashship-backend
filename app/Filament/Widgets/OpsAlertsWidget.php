<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\WithdrawRequestResource;
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
    /** Đơn chờ tài xế quá số phút này thì cảnh báo đỏ. */
    private const PENDING_WARN_MINUTES = 5;

    /** Yêu cầu rút tiền chờ quá số giờ này thì cảnh báo đỏ. */
    private const WITHDRAW_WARN_HOURS = 24;

    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static string $view = 'filament.widgets.ops-alerts';

    protected static ?string $pollingInterval = '15s';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    protected function getViewData(): array
    {
        $cityId = Filament::getTenant()?->id;
        $alerts = [];

        // Đơn chưa có tài xế (mọi ngày), kèm thời gian chờ của đơn cũ nhất.
        $pending = Order::query()
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->where('status', 'pending')
            ->selectRaw('COUNT(*) AS c, MIN(created_at) AS oldest')
            ->first();
        $pendingCount = (int) $pending->c;
        $waitMinutes = $pendingCount ? (int) Carbon::parse($pending->oldest)->diffInMinutes(now()) : 0;
        $alerts[] = [
            'label' => 'Đơn chưa có tài xế',
            'count' => $pendingCount,
            'detail' => $pendingCount ? "Đơn cũ nhất đã chờ {$waitMinutes} phút" : 'Không có đơn nào đang chờ',
            'level' => $pendingCount === 0 ? 'ok' : ($waitMinutes >= self::PENDING_WARN_MINUTES ? 'danger' : 'warn'),
            'url' => OrderResource::getUrl('index', ['activeTab' => 'new']),
            'icon' => 'heroicon-o-clock',
        ];

        // Đơn hệ thống đã hủy vì không tìm được tài xế hôm nay.
        $noDriver = Order::query()
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->where('status', 'cancelled')
            ->where('cancel_reason', 'no_driver')
            ->whereBetween('updated_at', [now()->startOfDay(), now()->endOfDay()])
            ->count();
        $alerts[] = [
            'label' => 'Không tìm được tài xế hôm nay',
            'count' => $noDriver,
            'detail' => $noDriver ? 'Đơn bị hủy do không có tài xế nhận' : 'Chưa có đơn nào bị bỏ lỡ',
            'level' => $noDriver === 0 ? 'ok' : 'danger',
            'url' => OrderResource::getUrl('index', ['activeTab' => 'cancelled']),
            'icon' => 'heroicon-o-exclamation-triangle',
        ];

        // Yêu cầu rút tiền chờ duyệt (chỉ hiện với người có quyền xem).
        if (WithdrawRequestResource::canViewAny()) {
            $withdraw = WithdrawRequestResource::scopeEloquentQueryToTenant(WithdrawRequest::query(), Filament::getTenant())
                ->where('status', 'pending')
                ->selectRaw('COUNT(*) AS c, MIN(created_at) AS oldest')
                ->first();
            $withdrawCount = (int) $withdraw->c;
            $waitHours = $withdrawCount ? (int) Carbon::parse($withdraw->oldest)->diffInHours(now()) : 0;
            $alerts[] = [
                'label' => 'Yêu cầu rút tiền chờ duyệt',
                'count' => $withdrawCount,
                'detail' => $withdrawCount ? "Yêu cầu cũ nhất đã chờ {$waitHours} giờ" : 'Không có yêu cầu nào chờ',
                'level' => $withdrawCount === 0 ? 'ok' : ($waitHours >= self::WITHDRAW_WARN_HOURS ? 'danger' : 'warn'),
                'url' => WithdrawRequestResource::getUrl('index'),
                'icon' => 'heroicon-o-banknotes',
            ];
        }

        $needsAttention = collect($alerts)->where('level', '!=', 'ok')->count();

        return compact('alerts', 'needsAttention');
    }
}
