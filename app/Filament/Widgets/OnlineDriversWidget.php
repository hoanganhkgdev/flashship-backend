<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Modules\Core\Models\User;

class OnlineDriversWidget extends Widget
{
    protected static string $view = 'filament.widgets.online-drivers';
    protected static ?string $pollingInterval = '15s';
    protected int | string | array $columnSpan = 'full';
    protected static ?int $sort = 2;

    public function getDrivers(): \Illuminate\Support\Collection
    {
        return User::where('user_type', 'driver')
            ->where('is_online', true)
            ->with('city')
            ->orderBy('online_since')
            ->get(['id', 'name', 'phone', 'city_id', 'is_online', 'online_since', 'driver_score']);
    }

    public function getTotalOnline(): int
    {
        return User::where('user_type', 'driver')->where('is_online', true)->count();
    }

    public function getTotalDrivers(): int
    {
        return User::where('user_type', 'driver')->where('status', 1)->count();
    }
}
