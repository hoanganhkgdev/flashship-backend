<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;

class StatsOverviewWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $today = now()->toDateString();

        $totalOrdersToday = Order::whereDate('created_at', $today)->count();

        $completedOrdersToday = Order::where('status', 'completed')
            ->whereDate('completed_at', $today)
            ->count();

        $driversOnline = User::where('user_type', 'driver')
            ->where('is_online', true)
            ->count();

        $revenueToday = Order::where('status', 'completed')
            ->whereDate('completed_at', $today)
            ->sum('shipping_fee');

        $revenueFormatted = number_format($revenueToday, 0, ',', '.') . ' ₫';

        return [
            Stat::make('Tổng đơn hôm nay', $totalOrdersToday)
                ->description('Đơn hàng tạo trong ngày')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('primary'),

            Stat::make('Đơn hoàn thành hôm nay', $completedOrdersToday)
                ->description('Đã giao thành công')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Tài xế đang online', $driversOnline)
                ->description('Tài xế sẵn sàng nhận đơn')
                ->descriptionIcon('heroicon-m-truck')
                ->color('info'),

            Stat::make('Doanh thu hôm nay', $revenueFormatted)
                ->description('Phí giao hàng đơn hoàn thành')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning'),
        ];
    }
}
