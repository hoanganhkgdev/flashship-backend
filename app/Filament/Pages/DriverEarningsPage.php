<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DriverEarningsPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Tổng quan';
    protected static ?string $navigationLabel = 'Thu nhập tài xế';
    protected static ?int    $navigationSort  = 2;
    protected static string  $view            = 'filament.pages.driver-earnings';

    public function getHeading(): string { return ''; }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->user_type, ['admin']);
    }

    public string $date = '';

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function getDriversProperty(): array
    {
        $date      = $this->date ?: now()->toDateString();
        $cityId    = \Filament\Facades\Filament::getTenant()?->id;
        $weekStart = Carbon::parse($date)->startOfWeek()->toDateString();
        $weekEnd   = Carbon::parse($date)->endOfWeek()->toDateString();

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('city_id', $cityId)
            ->where('status', '>=', 1)
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'is_online', 'daily_online_seconds', 'daily_online_date']);

        $driverIds = $drivers->pluck('id')->toArray();
        if (empty($driverIds)) return [];

        $todayStats = DB::table('orders')
            ->whereIn('delivery_man_id', $driverIds)
            ->where('status', 'completed')
            ->whereDate('completed_at', $date)
            ->groupBy('delivery_man_id')
            ->selectRaw('delivery_man_id, COUNT(*) as cnt, COALESCE(SUM(shipping_fee + bonus_fee), 0) as earnings')
            ->pluck('cnt', 'delivery_man_id')
            ->toArray();

        $todayEarnings = DB::table('orders')
            ->whereIn('delivery_man_id', $driverIds)
            ->where('status', 'completed')
            ->whereDate('completed_at', $date)
            ->groupBy('delivery_man_id')
            ->selectRaw('delivery_man_id, COALESCE(SUM(shipping_fee + bonus_fee), 0) as earnings')
            ->pluck('earnings', 'delivery_man_id')
            ->toArray();

        $weekStats = DB::table('orders')
            ->whereIn('delivery_man_id', $driverIds)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$weekStart . ' 00:00:00', $weekEnd . ' 23:59:59'])
            ->groupBy('delivery_man_id')
            ->selectRaw('delivery_man_id, COUNT(*) as cnt, COALESCE(SUM(shipping_fee + bonus_fee), 0) as earnings')
            ->get()
            ->keyBy('delivery_man_id');

        $result = [];
        foreach ($drivers as $d) {
            $week = $weekStats->get($d->id);
            $onlineSeconds = ($d->daily_online_date === $date) ? (int) $d->daily_online_seconds : 0;

            $result[] = [
                'id'              => $d->id,
                'name'            => $d->name,
                'phone'           => $d->phone,
                'is_online'       => $d->is_online,
                'today_orders'    => (int) ($todayStats[$d->id] ?? 0),
                'today_earnings'  => (int) ($todayEarnings[$d->id] ?? 0),
                'week_orders'     => (int) ($week->cnt ?? 0),
                'week_earnings'   => (int) ($week->earnings ?? 0),
                'online_hours'    => round($onlineSeconds / 3600, 1),
            ];
        }

        usort($result, fn($a, $b) => $b['today_earnings'] <=> $a['today_earnings']);

        return $result;
    }

    public function getTotalsProperty(): array
    {
        $drivers = $this->drivers;
        return [
            'drivers'        => count($drivers),
            'online'         => count(array_filter($drivers, fn($d) => $d['is_online'])),
            'today_orders'   => array_sum(array_column($drivers, 'today_orders')),
            'today_earnings' => array_sum(array_column($drivers, 'today_earnings')),
            'week_orders'    => array_sum(array_column($drivers, 'week_orders')),
            'week_earnings'  => array_sum(array_column($drivers, 'week_earnings')),
        ];
    }
}
