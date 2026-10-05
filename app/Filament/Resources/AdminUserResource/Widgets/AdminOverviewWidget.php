<?php

namespace App\Filament\Resources\AdminUserResource\Widgets;

use App\Services\AdminAccountService;
use Filament\Widgets\Widget;
use Modules\Core\Models\User;

/** KPI, bảng phân quyền và nhật ký đầu trang Quản trị viên. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class AdminOverviewWidget extends Widget
{
    protected static string $view = 'filament.resources.admin-user-resource.widgets.admin-overview';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $logs = AdminAccountService::recentLogs(8);
        $names = User::whereIn('id', $logs->pluck('performed_by')->merge($logs->pluck('entity_id'))->filter()->unique())->pluck('name', 'id');
        $labels = ['created' => 'Tạo tài khoản', 'updated' => 'Chỉnh sửa', 'locked' => 'Khóa', 'unlocked' => 'Mở khóa', 'password_reset' => 'Đặt lại mật khẩu', 'deleted' => 'Xóa tài khoản'];

        return [
            's' => AdminAccountService::summary(),
            'roles' => AdminAccountService::ROLE_DESCRIPTIONS,
            'roleNames' => AdminAccountService::ROLES,
            'logs' => $logs->map(fn ($l) => [
                'label' => $labels[$l->action] ?? $l->action, 'target' => $names[$l->entity_id] ?? '#'.$l->entity_id, 'detail' => $l->detail,
                'when' => $l->created_at, 'who' => $l->performed_by ? ($names[$l->performed_by] ?? 'Quản trị viên') : 'Hệ thống',
            ]),
        ];
    }
}
