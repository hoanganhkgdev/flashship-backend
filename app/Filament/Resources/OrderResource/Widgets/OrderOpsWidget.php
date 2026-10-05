<?php

namespace App\Filament\Resources\OrderResource\Widgets;

use App\Filament\Resources\OrderResource;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\OperationalSettings;

/** KPI đầu trang Đơn hàng. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class OrderOpsWidget extends Widget
{
    protected static string $view = 'filament.resources.order-resource.widgets.order-ops';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /** Cặp đơn nghi trùng: cùng SĐT lấy và địa chỉ lấy, tạo cách nhau dưới 10 phút. */
    public static function duplicatePairs(?int $cityId, \Carbon\Carbon $since): int
    {
        $bindings = [$since];
        $cityClause = '';
        if ($cityId) {
            $cityClause = ' AND a.city_id = ?';
            $bindings[] = $cityId;
        }

        return (int) (DB::selectOne(
            "SELECT COUNT(*) c FROM orders a
              JOIN orders b ON a.pickup_phone = b.pickup_phone AND a.pickup_address = b.pickup_address
               AND b.id > a.id AND b.created_at BETWEEN a.created_at AND DATE_ADD(a.created_at, INTERVAL 10 MINUTE)
             WHERE a.created_at >= ? AND a.pickup_phone IS NOT NULL AND a.pickup_phone <> ''".$cityClause,
            $bindings,
        )->c ?? 0);
    }

    protected function getViewData(): array
    {
        $cityId = Filament::getTenant()?->id;
        $q = fn () => DB::table('orders')->when($cityId, fn ($x) => $x->where('city_id', $cityId));

        $today = $q()->whereDate('created_at', today())->selectRaw("COUNT(*) total, SUM(status='cancelled') canc, SUM(status IN ('assigned','processing')) active")->first();
        $pending = $q()->where('status', 'pending')->selectRaw('COUNT(*) c, MIN(created_at) oldest')->first();
        $timeout = OperationalSettings::dispatchTimeoutMinutes($cityId);
        $oldest = $pending->oldest ? (int) \Carbon\Carbon::parse($pending->oldest)->diffInMinutes(now()) : 0;
        $quick = $q()->whereDate('created_at', today())->where('status', 'cancelled')->whereRaw('TIMESTAMPDIFF(MINUTE, created_at, COALESCE(cancelled_at, updated_at)) < 3')->count();

        return [
            'total' => (int) $today->total,
            'active' => (int) $today->active,
            'pending' => (int) $pending->c,
            'oldest' => $oldest,
            'timeout' => $timeout,
            'cancelRate' => $today->total ? round($today->canc / $today->total * 100) : 0,
            'cancelled' => (int) $today->canc,
            'quick' => $quick,
            'dupes' => self::duplicatePairs($cityId, now()->startOfDay()),
        ];
    }
}
