<?php

namespace App\Filament\Resources\ShiftResource\Widgets;

use App\Filament\Resources\DriverShiftChangeRequestResource;
use App\Services\ShiftService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/** KPI + phủ ca đầu trang Ca làm việc. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class ShiftCoverageWidget extends Widget
{
    protected static string $view = 'filament.resources.shift-resource.widgets.shift-coverage';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $cityId = (int) Filament::getTenant()?->id;

        return [
            's' => ShiftService::summary($cityId),
            'overload' => ShiftService::OVERLOAD_PER_DRIVER,
            'changesUrl' => DriverShiftChangeRequestResource::getUrl('index'),
        ];
    }
}
