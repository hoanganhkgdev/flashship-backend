<?php

namespace App\Filament\Resources\WithdrawRequestResource\Widgets;

use App\Services\WithdrawService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/** KPI đầu trang Yêu cầu rút tiền. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class WithdrawStatsWidget extends Widget
{
    protected static string $view = 'filament.resources.withdraw-request-resource.widgets.withdraw-stats';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        return ['s' => WithdrawService::summary(Filament::getTenant()?->id), 'staleHours' => WithdrawService::STALE_HOURS];
    }
}
