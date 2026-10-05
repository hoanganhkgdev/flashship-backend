<?php

namespace App\Filament\PageWidgets;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Modules\Core\Models\ConfigLog;
use Modules\Core\Models\User;

/** Lịch sử thay đổi cấu hình vận hành của khu vực. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class OperationalHistoryWidget extends Widget
{
    protected static string $view = 'filament.page-widgets.operational-history';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $cityId = (int) Filament::getTenant()?->getKey();
        $logs = ConfigLog::where('entity', 'operational')->where('entity_id', $cityId)->latest('id')->limit(10)->get();
        $names = User::whereIn('id', $logs->pluck('performed_by')->filter()->unique())->pluck('name', 'id');

        return [
            'city' => Filament::getTenant()?->name,
            'logs' => $logs->map(fn ($l) => [
                'when' => $l->created_at, 'detail' => $l->detail,
                'who' => $l->performed_by ? ($names[$l->performed_by] ?? 'Quản trị viên') : 'Hệ thống',
            ]),
        ];
    }
}
