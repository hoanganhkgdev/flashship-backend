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

    private function cityId(): ?int
    {
        $user = auth()->user();
        return $user?->user_type === 'city_manager' ? $user->city_id : null;
    }

    public function getDrivers(): \Illuminate\Support\Collection
    {
        return User::where('user_type', 'driver')
            ->where('is_online', true)
            ->when($this->cityId(), fn ($q) => $q->where('city_id', $this->cityId()))
            ->with('city')
            ->orderBy('online_since')
            ->get(['id', 'name', 'phone', 'city_id', 'is_online', 'online_since', 'driver_score', 'profile_photo_path']);
    }

    public function getTotalOnline(): int
    {
        return User::where('user_type', 'driver')
            ->where('is_online', true)
            ->when($this->cityId(), fn ($q) => $q->where('city_id', $this->cityId()))
            ->count();
    }

    public function getTotalDrivers(): int
    {
        return User::where('user_type', 'driver')
            ->where('status', 1)
            ->when($this->cityId(), fn ($q) => $q->where('city_id', $this->cityId()))
            ->count();
    }
}
