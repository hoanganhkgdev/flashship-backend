<?php

namespace App\Filament\Resources\UserResource\Widgets;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/** Dải chỉ số đầu trang Khách hàng. Đặt ngoài app/Filament/Widgets để không bị tự thêm vào Tổng quan. */
class CustomerStatsWidget extends Widget
{
    protected static string $view = 'filament.resources.user-resource.widgets.customer-stats';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $cityId = Filament::getTenant()?->id;

        $customers = DB::table('users')->where('user_type', 'customer')->where('city_id', $cityId)
            ->selectRaw('COUNT(*) as total, SUM(status = 2) as locked, SUM(created_at >= ?) as new_recent, SUM(created_at >= ?) as new_month', [now()->subDays(ListUsers::NEW_DAYS), now()->subDays(30)])
            ->first();

        // Mỗi khách có bao nhiêu đơn (đã đặt) và bao nhiêu đơn đã hoàn thành, chỉ tính đơn từ app khách hàng.
        $perCustomer = DB::table('orders as o')
            ->join('users as u', 'u.id', '=', 'o.sender_platform_id')
            ->where('u.user_type', 'customer')->where('u.city_id', $cityId)->where('o.platform', 'customer_app')
            ->groupBy('o.sender_platform_id')
            ->selectRaw("COUNT(*) as orders, SUM(o.status = 'completed') as done")
            ->get();

        $total = (int) $customers->total;
        $ordered = $perCustomer->count();

        // Đơn tổng đài trong 30 ngày qua không ghi số điện thoại nên không gắn được với khách nào.
        $callCenter = DB::table('orders')->where('platform', 'call_center')->where('city_id', $cityId)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw("COUNT(*) as total, SUM(pickup_phone IS NULL OR pickup_phone = '') as no_phone")
            ->first();

        return [
            'total' => $total,
            'newRecent' => (int) $customers->new_recent,
            'newMonth' => (int) $customers->new_month,
            'newDays' => ListUsers::NEW_DAYS,
            'locked' => (int) $customers->locked,
            'ordered' => $ordered,
            'orderedRate' => $total ? round($ordered / $total * 100) : 0,
            'returning' => $perCustomer->where('done', '>=', 2)->count(),
            'returningRate' => $ordered ? round($perCustomer->where('done', '>=', 2)->count() / $ordered * 100) : 0,
            'loyal' => $perCustomer->where('done', '>=', ListUsers::LOYAL_MIN_ORDERS)->count(),
            'loyalMin' => ListUsers::LOYAL_MIN_ORDERS,
            'callCenterTotal' => (int) $callCenter->total,
            'callCenterNoPhone' => (int) $callCenter->no_phone,
            'callCenterNoPhoneRate' => $callCenter->total ? round($callCenter->no_phone / $callCenter->total * 100) : 0,
        ];
    }
}
