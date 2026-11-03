<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\DriverMapPage;
use App\Filament\Support\TenantCity;
use App\Services\DriverSupplyService;
use Filament\Widgets\Widget;
use Modules\Order\Models\Order;

class OnlineDriversWidget extends Widget
{
    use TenantCity;

    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static string $view = 'filament.widgets.online-drivers';

    protected int|string|array $columnSpan = ['default' => 12, 'xl' => 4];

    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected function getViewData(): array
    {
        $cityId = $this->cityId();

        return [
            'supply' => app(DriverSupplyService::class)->snapshot($cityId),
            'pending' => Order::query()->where('city_id', $cityId)->where('status', 'pending')->count(),
            'mapUrl' => DriverMapPage::canAccess() ? DriverMapPage::getUrl() : null,
        ];
    }
}
