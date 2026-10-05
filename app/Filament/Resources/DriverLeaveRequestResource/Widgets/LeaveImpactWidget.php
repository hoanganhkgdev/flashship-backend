<?php

namespace App\Filament\Resources\DriverLeaveRequestResource\Widgets;

use App\Services\DriverLeaveService;
use App\Services\ShiftService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/** KPI + lịch nghỉ 7 ngày tới đầu trang Xin nghỉ phép. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class LeaveImpactWidget extends Widget
{
    protected static string $view = 'filament.resources.driver-leave-request-resource.widgets.leave-impact';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $cityId = (int) Filament::getTenant()?->id;

        return [
            's' => DriverLeaveService::summary($cityId),
            'impact' => DriverLeaveService::impact($cityId),
            'overload' => ShiftService::OVERLOAD_PER_DRIVER,
            'frequentAt' => DriverLeaveService::FREQUENT_AT,
        ];
    }
}
