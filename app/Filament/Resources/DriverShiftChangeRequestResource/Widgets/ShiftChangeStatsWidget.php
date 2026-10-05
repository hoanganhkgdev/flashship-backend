<?php

namespace App\Filament\Resources\DriverShiftChangeRequestResource\Widgets;

use App\Services\ShiftChangeService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/** KPI đầu trang Yêu cầu đổi ca. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class ShiftChangeStatsWidget extends Widget
{
    protected static string $view = 'filament.resources.driver-shift-change-request-resource.widgets.shift-change-stats';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        return ['s' => ShiftChangeService::summary(Filament::getTenant()?->id), 'staleHours' => ShiftChangeService::STALE_HOURS];
    }
}
