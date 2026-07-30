<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;

class StatsOverviewWidget extends Widget
{
    public static function canView(): bool
    {
        return !auth()->user()?->isCallCenter();
    }

    protected static string $view = 'filament.widgets.stats-overview';
    protected static ?string $pollingInterval = '15s';
    protected int | string | array $columnSpan = 'full';
    protected static ?int $sort = 1;

    protected function getViewData(): array
    {
        $today  = now()->toDateString();
        $cityId = \Filament\Facades\Filament::getTenant()?->id;

        $orderBase = Order::query()->when($cityId, fn ($q) => $q->where('city_id', $cityId));

        $totalOrders     = (clone $orderBase)->whereDate('created_at', $today)->count();
        $completedOrders = (clone $orderBase)->where('status', 'completed')->whereDate('completed_at', $today)->count();
        $revenueRaw      = (clone $orderBase)->where('status', 'completed')->whereDate('completed_at', $today)->sum('shipping_fee');
        $revenue         = number_format($revenueRaw, 0, ',', '.') . '₫';

        $driverBase  = User::where('user_type', 'driver')->where('is_online', true);
        $driversOnline = (clone $driverBase)->when($cityId, fn ($q) => $q->where('city_id', $cityId))->count();

        return compact('totalOrders', 'completedOrders', 'driversOnline', 'revenue');
    }
}
