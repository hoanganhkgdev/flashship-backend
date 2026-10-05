<?php

namespace App\Filament\Resources\VoucherResource\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Voucher;

/** Dải chỉ số đầu trang Mã giảm giá. Nằm ngoài app/Filament/Widgets để không bị tự thêm vào Tổng quan. */
class VoucherStatsWidget extends Widget
{
    protected static string $view = 'filament.resources.voucher-resource.widgets.voucher-stats';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /** 'customer' hoặc 'shop' — do trang danh sách truyền vào. */
    public string $audience = 'customer';

    /** Cảnh báo "sắp hết" khi mã đã dùng từ ngần này phần trăm giới hạn lượt trở lên. */
    private const NEAR_LIMIT = 0.8;

    private const EXPIRING_DAYS = 7;

    protected function getViewData(): array
    {
        $cityId = Filament::getTenant()?->id;
        $scope = fn ($q) => $q->where('vouchers.audience', $this->audience)
            ->where(fn ($w) => $w->whereNull('vouchers.city_id')->orWhere('vouchers.city_id', $cityId));
        $since = now()->subDays(30);

        $total = $scope(Voucher::query())->count();
        $active = $scope(Voucher::available())->count();

        $usage = $scope(DB::table('voucher_usages as u')->join('vouchers', 'vouchers.id', '=', 'u.voucher_id'))
            ->where('u.used_at', '>=', $since)->count();

        $completedAll = DB::table('orders')->where('city_id', $cityId)->where('status', 'completed')->where('completed_at', '>=', $since)->count();
        $cost = $scope(DB::table('orders as o')->join('vouchers', 'vouchers.code', '=', 'o.voucher_code'))
            ->where('o.city_id', $cityId)->where('o.status', 'completed')->where('o.completed_at', '>=', $since)
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(o.discount_amount), 0) as cost')->first();

        $attention = $scope(Voucher::available())->where(fn ($q) => $q
            ->whereBetween('vouchers.expires_at', [now(), now()->addDays(self::EXPIRING_DAYS)])
            ->orWhere(fn ($w) => $w->whereNotNull('vouchers.usage_limit')->whereRaw('vouchers.used_count >= vouchers.usage_limit * ?', [self::NEAR_LIMIT])))
            ->get(['code', 'expires_at', 'usage_limit', 'used_count']);

        return [
            'noun' => $this->audience === 'shop' ? 'cửa hàng' : 'khách',
            'total' => $total, 'active' => $active,
            'usage' => $usage,
            'cost' => (int) $cost->cost, 'voucherOrders' => (int) $cost->orders,
            'orderShare' => $completedAll ? round($cost->orders / $completedAll * 100, 1) : 0,
            'attention' => $attention,
            'expiringDays' => self::EXPIRING_DAYS, 'nearPct' => (int) (self::NEAR_LIMIT * 100),
        ];
    }
}
