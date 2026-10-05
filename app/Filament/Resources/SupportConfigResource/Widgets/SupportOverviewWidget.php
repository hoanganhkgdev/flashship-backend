<?php

namespace App\Filament\Resources\SupportConfigResource\Widgets;

use App\Services\CatalogService;
use App\Services\SupportChannelService;
use Filament\Widgets\Widget;
use Modules\Core\Models\User;

/** KPI + nhật ký đầu trang Kênh hỗ trợ. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class SupportOverviewWidget extends Widget
{
    protected static string $view = 'filament.resources.support-config-resource.widgets.support-overview';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $logs = \Modules\Core\Models\ConfigLog::where('entity', 'support_config')->latest('id')->limit(6)->get();
        $names = User::whereIn('id', $logs->pluck('performed_by')->filter()->unique())->pluck('name', 'id');
        $labels = ['created' => 'Thêm kênh', 'updated' => 'Chỉnh sửa', 'activated' => 'Hiện kênh', 'deactivated' => 'Ẩn kênh', 'deleted' => 'Xóa kênh'];

        return [
            's' => SupportChannelService::summary(),
            'logs' => $logs->map(fn ($l) => ['label' => $labels[$l->action] ?? $l->action, 'detail' => $l->detail, 'when' => $l->created_at, 'who' => $l->performed_by ? ($names[$l->performed_by] ?? 'Quản trị viên') : 'Hệ thống']),
        ];
    }
}
