<?php

namespace App\Support;

use Modules\Core\Models\User;

/** Ma trận quyền duy nhất cho các tài khoản truy cập trang quản trị. */
final class AdminAccess
{
    public const ADMIN_ACCOUNTS = 'admin_accounts';

    public const SYSTEM_SETTINGS = 'system_settings';

    public const OPERATIONS_VIEW = 'operations_view';

    public const OPERATIONS_MANAGE = 'operations_manage';

    public const ORDERS_VIEW = 'orders_view';

    public const ORDERS_MANAGE = 'orders_manage';

    public const FINANCE_VIEW = 'finance_view';

    public const FINANCE_MANAGE = 'finance_manage';

    public const REPORTS_VIEW = 'reports_view';

    public const EXPORT_DATA = 'export_data';

    private const MATRIX = [
        'admin' => ['*'],
        // Vai trò cũ, chỉ giữ để tài khoản lịch sử vẫn đăng nhập an toàn.
        'subadmin' => [self::OPERATIONS_VIEW, self::OPERATIONS_MANAGE, self::ORDERS_VIEW, self::ORDERS_MANAGE, self::REPORTS_VIEW],
        'city_manager' => [self::OPERATIONS_VIEW, self::OPERATIONS_MANAGE, self::ORDERS_VIEW, self::ORDERS_MANAGE, self::REPORTS_VIEW],
        'call_center' => [self::OPERATIONS_VIEW, self::ORDERS_VIEW, self::ORDERS_MANAGE],
        'accountant' => [self::ORDERS_VIEW, self::FINANCE_VIEW, self::FINANCE_MANAGE, self::REPORTS_VIEW, self::EXPORT_DATA],
        'viewer' => [self::OPERATIONS_VIEW, self::ORDERS_VIEW, self::REPORTS_VIEW],
    ];

    public static function allows(?User $user, string $permission): bool
    {
        if (! $user || (int) $user->status !== 1) {
            return false;
        }

        $grants = self::MATRIX[$user->user_type] ?? [];

        return in_array('*', $grants, true) || in_array($permission, $grants, true);
    }

    public static function isAdminType(?User $user): bool
    {
        return $user !== null && array_key_exists($user->user_type, self::MATRIX);
    }

    public static function isReadOnly(?User $user): bool
    {
        return $user?->user_type === 'viewer';
    }
}
