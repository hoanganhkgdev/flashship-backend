<?php

namespace App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;

class OnlineDriversWidget extends Widget
{
    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static string $view = 'filament.widgets.online-drivers';

    protected static ?string $pollingInterval = '15s';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    private function cityId(): ?int
    {
        return Filament::getTenant()?->id;
    }

    public function getDrivers(): Collection
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
