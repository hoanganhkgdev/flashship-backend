<?php

namespace App\Filament\Pages;

use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class DriverEarningsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Tài chính tài xế';

    protected static ?string $navigationLabel = 'Thu nhập tài xế';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.driver-earnings';

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->user_type, ['admin']);
    }

    public string $date = '';

    public string $search = '';

    public string $onlineStatus = 'all';

    public string $activity = 'all';

    public string $sortBy = 'day_earnings';

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function getDriversProperty(): array
    {
        $date = $this->date ?: now()->toDateString();
        $cityId = Filament::getTenant()?->id;
        $weekStart = Carbon::parse($date)->startOfWeek()->toDateString();
        $weekEnd = Carbon::parse($date)->endOfWeek()->toDateString();

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('city_id', $cityId)
            ->where('status', '>=', 1)
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'is_online']);

        $driverIds = $drivers->pluck('id')->toArray();
        if (empty($driverIds)) {
            return [];
        }

        $todayStats = DB::table('orders')
            ->whereIn('delivery_man_id', $driverIds)
            ->where('status', 'completed')
            ->whereDate('completed_at', $date)
            ->groupBy('delivery_man_id')
            ->selectRaw('delivery_man_id, COUNT(*) as cnt,
                COALESCE(SUM(shipping_fee), 0) as shipping,
                COALESCE(SUM(bonus_fee), 0) as bonus,
                COALESCE(SUM(shipping_fee + bonus_fee), 0) as earnings')
            ->get()
            ->keyBy('delivery_man_id');

        $weekStats = DB::table('orders')
            ->whereIn('delivery_man_id', $driverIds)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$weekStart.' 00:00:00', $weekEnd.' 23:59:59'])
            ->groupBy('delivery_man_id')
            ->selectRaw('delivery_man_id, COUNT(*) as cnt,
                COALESCE(SUM(shipping_fee), 0) as shipping,
                COALESCE(SUM(bonus_fee), 0) as bonus,
                COALESCE(SUM(shipping_fee + bonus_fee), 0) as earnings')
            ->get()
            ->keyBy('delivery_man_id');

        $result = [];
        foreach ($drivers as $d) {
            $week = $weekStats->get($d->id);
            $today = $todayStats->get($d->id);

            $result[] = [
                'id' => $d->id,
                'name' => $d->name,
                'phone' => $d->phone,
                'is_online' => $d->is_online,
                'today_orders' => (int) ($today->cnt ?? 0),
                'today_shipping' => (int) ($today->shipping ?? 0),
                'today_bonus' => (int) ($today->bonus ?? 0),
                'today_earnings' => (int) ($today->earnings ?? 0),
                'week_orders' => (int) ($week->cnt ?? 0),
                'week_shipping' => (int) ($week->shipping ?? 0),
                'week_bonus' => (int) ($week->bonus ?? 0),
                'week_earnings' => (int) ($week->earnings ?? 0),
            ];
        }

        $search = mb_strtolower(trim($this->search));
        $result = array_values(array_filter($result, function (array $driver) use ($search) {
            if ($search !== '' && ! str_contains(mb_strtolower($driver['name'].' '.$driver['phone']), $search)) {
                return false;
            }
            if ($this->onlineStatus === 'online' && ! $driver['is_online']) {
                return false;
            }
            if ($this->onlineStatus === 'offline' && $driver['is_online']) {
                return false;
            }
            if ($this->activity === 'has_orders' && $driver['today_orders'] === 0) {
                return false;
            }
            if ($this->activity === 'no_orders' && $driver['today_orders'] > 0) {
                return false;
            }

            return true;
        }));

        $sortKey = $this->sortBy === 'week_earnings' ? 'week_earnings' : 'today_earnings';
        usort($result, fn ($a, $b) => $b[$sortKey] <=> $a[$sortKey] ?: strcasecmp($a['name'], $b['name']));

        return $result;
    }

    public function getTotalsProperty(): array
    {
        $drivers = $this->drivers;

        return [
            'drivers' => count($drivers),
            'online' => count(array_filter($drivers, fn ($d) => $d['is_online'])),
            'earning_drivers' => count(array_filter($drivers, fn ($d) => $d['today_orders'] > 0)),
            'today_orders' => array_sum(array_column($drivers, 'today_orders')),
            'today_earnings' => array_sum(array_column($drivers, 'today_earnings')),
            'week_orders' => array_sum(array_column($drivers, 'week_orders')),
            'week_earnings' => array_sum(array_column($drivers, 'week_earnings')),
        ];
    }

    public function getDateLabelProperty(): string
    {
        return Carbon::parse($this->date ?: now()->toDateString())->format('d/m/Y');
    }

    public function getWeekLabelProperty(): string
    {
        $date = Carbon::parse($this->date ?: now()->toDateString());

        return $date->copy()->startOfWeek()->format('d/m').' – '.$date->copy()->endOfWeek()->format('d/m/Y');
    }
}
