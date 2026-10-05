<?php

namespace App\Filament\Resources\DriverDebtResource\Widgets;

use App\Filament\Resources\DriverResource;
use App\Services\DriverDebtService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/** KPI + danh sách tài xế bị chặn đầu trang Công nợ. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class DriverDebtStatsWidget extends Widget
{
    protected static string $view = 'filament.resources.driver-debt-resource.widgets.driver-debt-stats';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $cityId = Filament::getTenant()?->id;

        return [
            's' => DriverDebtService::summary($cityId),
            'blocked' => DriverDebtService::blockedDrivers($cityId, 5)->map(fn ($b) => (array) $b + ['url' => DriverResource::getUrl('view', ['record' => $b->id])]),
        ];
    }
}
