<?php

namespace App\Filament\Resources\DriverWalletResource\Widgets;

use App\Filament\Resources\DriverResource;
use App\Services\DriverWalletReport;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/** KPI + dòng tiền đầu trang Ví tài xế. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class DriverWalletStatsWidget extends Widget
{
    protected static string $view = 'filament.resources.driver-wallet-resource.widgets.driver-wallet-stats';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $cityId = Filament::getTenant()?->id;

        return [
            's' => DriverWalletReport::summary($cityId),
            'flow' => DriverWalletReport::cashflow($cityId, 30),
            'adjustments' => DriverWalletReport::recentAdminAdjustments($cityId, 8)
                ->map(fn ($a) => (array) $a + ['url' => DriverResource::getUrl('view', ['record' => $a->driver_id])]),
        ];
    }
}
