<?php

namespace App\Filament\Resources\ShopResource\Widgets;

use App\Filament\Resources\ShopResource;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/** Dải chỉ số đầu trang Cửa hàng. Nằm ngoài app/Filament/Widgets để không bị tự thêm vào Tổng quan. */
class ShopStatsWidget extends Widget
{
    protected static string $view = 'filament.resources.shop-resource.widgets.shop-stats';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /** Số lấy hàng phải có ít nhất bấy nhiêu đơn tổng đài mới được gợi ý mời vào app. */
    private const LEAD_MIN_ORDERS = 10;

    private const LEAD_LIMIT = 5;

    protected function getViewData(): array
    {
        $cityId = Filament::getTenant()?->id;
        $cutoff = now()->subDays(ShopResource::ACTIVE_DAYS)->toDateTimeString();

        $shops = ShopResource::joinCallCenter(DB::table('users'))->where('user_type', 'shop')->where('users.city_id', $cityId)
            ->selectRaw('users.status, ('.ShopResource::LAST_ACTIVITY.') as last_activity')->get();

        $total = $shops->count();
        $never = $shops->filter(fn ($s) => str_starts_with((string) $s->last_activity, '1970'))->count();
        $active = $shops->filter(fn ($s) => $s->last_activity >= $cutoff)->count();

        // Mức tập trung: các shop đứng đầu chiếm bao nhiêu phần trăm đơn của app cửa hàng.
        $perShop = DB::table('orders')->where('platform', 'shop_app')->where('city_id', $cityId)->whereNotNull('sender_platform_id')
            ->groupBy('sender_platform_id')->selectRaw('COUNT(*) as n')->orderByDesc('n')->pluck('n');
        $orders = (int) $perShop->sum();
        $top = (int) $perShop->take(2)->sum();

        $recent = DB::table('orders')->where('city_id', $cityId)->where('created_at', '>=', now()->subDays(30))
            ->selectRaw("COUNT(*) as total, SUM(platform = 'shop_app') as shop_app")->first();

        // Số lấy hàng hay gọi tổng đài nhưng chưa phải shop đã đăng ký: gợi ý để mời vào app.
        $key = "RIGHT(REPLACE(REPLACE(REPLACE(o.pickup_phone, ' ', ''), '.', ''), '+', ''), 9)";
        $leads = DB::table('orders as o')->where('o.platform', 'call_center')->where('o.city_id', $cityId)
            ->whereNotNull('o.pickup_phone')->whereRaw('CHAR_LENGTH(o.pickup_phone) >= 9')
            ->whereRaw("NOT EXISTS (SELECT 1 FROM users s WHERE s.user_type = 'shop' AND RIGHT(s.phone, 9) = $key)")
            ->groupByRaw($key)->havingRaw('COUNT(*) >= ?', [self::LEAD_MIN_ORDERS])->orderByRaw('COUNT(*) DESC')->limit(self::LEAD_LIMIT)
            ->selectRaw("$key as k, MAX(o.pickup_phone) as raw_phone, COUNT(*) as n, MAX(o.created_at) as last_at")->get()
            ->map(function ($r) {
                // Hiện số gốc (kể cả điện thoại bàn có mã vùng); chỉ đổi đầu 84 thành 0.
                $digits = preg_replace('/\D/', '', (string) $r->raw_phone);
                $phone = str_starts_with($digits, '84') && strlen($digits) >= 11 ? '0'.substr($digits, 2) : $digits;

                return ['phone' => $phone, 'orders' => (int) $r->n, 'last' => \Carbon\Carbon::parse($r->last_at)];
            });

        return [
            'total' => $total,
            'locked' => $shops->where('status', 2)->count(),
            'active' => $active, 'activeRate' => $total ? round($active / $total * 100) : 0,
            'dormant' => $total - $active - $never, 'never' => $never,
            'topShare' => $orders ? round($top / $orders * 100) : 0, 'orders' => $orders,
            'activeDays' => ShopResource::ACTIVE_DAYS,
            'shopAppShare' => $recent->total ? round($recent->shop_app / $recent->total * 100, 1) : 0,
            'leads' => $leads, 'leadMin' => self::LEAD_MIN_ORDERS,
        ];
    }
}
