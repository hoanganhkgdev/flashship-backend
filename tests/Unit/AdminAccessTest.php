<?php

namespace Tests\Unit;

use App\Support\AdminAccess;
use Modules\Core\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminAccessTest extends TestCase
{
    public static function permissions(): array
    {
        return [
            'admin has system access' => ['admin', AdminAccess::SYSTEM_SETTINGS, true],
            'manager manages operations' => ['city_manager', AdminAccess::OPERATIONS_MANAGE, true],
            'manager cannot see finance' => ['city_manager', AdminAccess::FINANCE_VIEW, false],
            'manager cannot export data' => ['city_manager', AdminAccess::EXPORT_DATA, false],
            'call center manages orders' => ['call_center', AdminAccess::ORDERS_MANAGE, true],
            'call center cannot change operations' => ['call_center', AdminAccess::OPERATIONS_MANAGE, false],
            'accountant manages finance' => ['accountant', AdminAccess::FINANCE_MANAGE, true],
            'accountant can export data' => ['accountant', AdminAccess::EXPORT_DATA, true],
            'accountant cannot dispatch' => ['accountant', AdminAccess::ORDERS_MANAGE, false],
            'viewer can view orders' => ['viewer', AdminAccess::ORDERS_VIEW, true],
            'viewer cannot change orders' => ['viewer', AdminAccess::ORDERS_MANAGE, false],
        ];
    }

    #[DataProvider('permissions')]
    public function test_role_matrix(string $role, string $permission, bool $expected): void
    {
        $user = new User(['user_type' => $role, 'status' => 1]);

        self::assertSame($expected, AdminAccess::allows($user, $permission));
    }

    public function test_locked_admin_has_no_access(): void
    {
        $user = new User(['user_type' => 'admin', 'status' => 2]);

        self::assertFalse(AdminAccess::allows($user, AdminAccess::SYSTEM_SETTINGS));
    }
}
