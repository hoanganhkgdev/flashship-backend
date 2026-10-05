<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;

/** Chốt chặn và thao tác có nhật ký cho tài khoản quản trị. */
class AdminAccountService
{
    public const ROLES = ['admin' => 'Quản trị viên', 'subadmin' => 'Quản trị viên phụ', 'city_manager' => 'Quản lý khu vực', 'call_center' => 'Tổng đài viên'];

    public const ROLE_DESCRIPTIONS = [
        'admin' => 'Toàn quyền: tiền, điểm, giá cước, tài khoản quản trị và cấu hình hệ thống.',
        'subadmin' => 'Quản lý mọi khu vực nhưng không vào được tiền (ví, công nợ, rút tiền), điểm, giá và tài khoản quản trị.',
        'city_manager' => 'Chỉ khu vực được gán; không vào được cấu hình hệ thống và các mục chỉ dành cho quản trị.',
        'call_center' => 'Chỉ khu vực được gán; chỉ tổng đài đặt đơn và đơn hàng.',
    ];

    public static function activeAdmins(): int
    {
        return User::where('user_type', 'admin')->where('status', 1)->count();
    }

    private static function isLastActiveAdmin(User $u): bool
    {
        return $u->user_type === 'admin' && (int) $u->status === 1 && self::activeAdmins() <= 1;
    }

    /** Lý do không được khóa / hạ quyền / xóa tài khoản này, null nếu được phép. */
    public static function blocker(User $target, ?User $actor, string $operation): ?string
    {
        if ($actor && $target->is($actor)) {
            return match ($operation) {
                'lock' => 'Bạn không thể tự khóa tài khoản của mình.',
                'delete' => 'Bạn không thể tự xóa tài khoản của mình.',
                default => 'Bạn không thể tự hạ quyền của mình.',
            };
        }
        if (self::isLastActiveAdmin($target)) {
            return 'Đây là quản trị viên đầy đủ cuối cùng đang hoạt động, hệ thống phải còn ít nhất một người.';
        }

        return null;
    }

    public static function log(User $target, string $action, ?int $by, ?string $detail = null): void
    {
        CatalogService::log('admin_user', $target->id, $action, $by, $detail);
    }

    /** @return array{ok:bool,message:string} */
    public static function setLocked(User $target, bool $lock, string $reason, ?User $actor): array
    {
        if ($lock && ($why = self::blocker($target, $actor, 'lock'))) {
            return ['ok' => false, 'message' => $why];
        }
        if (mb_strlen(trim($reason)) < 3) {
            return ['ok' => false, 'message' => 'Hãy ghi lý do (ít nhất 3 ký tự).'];
        }
        $target->update(['status' => $lock ? 2 : 1]);
        // Khóa là cắt phiên đăng nhập hiện có (token API nếu có) để hiệu lực ngay.
        if ($lock && method_exists($target, 'tokens')) {
            $target->tokens()->delete();
        }
        self::log($target, $lock ? 'locked' : 'unlocked', $actor?->id, trim($reason));

        return ['ok' => true, 'message' => $lock ? 'Đã khóa tài khoản.' : 'Đã mở khóa tài khoản.'];
    }

    /** @return array{ok:bool,message:string} */
    public static function resetPassword(User $target, string $password, ?User $actor): array
    {
        if (mb_strlen($password) < 8) {
            return ['ok' => false, 'message' => 'Mật khẩu tối thiểu 8 ký tự.'];
        }
        $target->update(['password' => Hash::make($password)]);
        if (method_exists($target, 'tokens')) {
            $target->tokens()->delete();
        }
        self::log($target, 'password_reset', $actor?->id);

        return ['ok' => true, 'message' => 'Đã đặt lại mật khẩu.'];
    }

    /** Mọi cột lưu "ai đã thao tác": nếu tài khoản xuất hiện ở đây thì có lịch sử và không được xóa. */
    private const ACTOR_COLUMNS = [
        'cities' => 'rain_mode_by', 'config_logs' => 'performed_by', 'driver_debt_logs' => 'performed_by', 'driver_leave_logs' => 'performed_by',
        'driver_leave_requests' => 'created_by', 'driver_score_logs' => 'performed_by', 'driver_score_reset_requests' => 'processed_by',
        'driver_shift_change_requests' => 'processed_by', 'driver_status_logs' => 'performed_by', 'driver_wallet_transactions' => 'performed_by',
        'legal_page_versions' => 'performed_by', 'notification_campaigns' => 'created_by', 'orders' => 'created_by',
        'shift_logs' => 'performed_by', 'withdraw_requests' => 'processed_by',
    ];

    public static function hasHistory(User $u): bool
    {
        foreach (self::ACTOR_COLUMNS as $table => $column) {
            if (Schema::hasColumn($table, $column) && DB::table($table)->where($column, $u->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Xóa chỉ khi tài khoản chưa có lịch sử thao tác; còn lại phải Khóa. */
    public static function delete(User $target, ?User $actor): array
    {
        if ($why = self::blocker($target, $actor, 'delete')) {
            return ['ok' => false, 'message' => $why];
        }
        if (self::hasHistory($target)) {
            return ['ok' => false, 'message' => 'Tài khoản đã có lịch sử thao tác nên không xóa được. Hãy khóa tài khoản thay vì xóa.'];
        }
        try {
            DB::transaction(fn () => $target->delete());
        } catch (QueryException $e) {
            return ['ok' => false, 'message' => 'Tài khoản đã có lịch sử thao tác nên không xóa được. Hãy khóa tài khoản thay vì xóa.'];
        }
        self::log($target, 'deleted', $actor?->id, $target->name.' ('.$target->email.')');

        return ['ok' => true, 'message' => 'Đã xóa tài khoản.'];
    }

    public static function summary(): array
    {
        $q = User::whereIn('user_type', array_keys(self::ROLES));

        return [
            'total' => (clone $q)->count(),
            'admins' => (clone $q)->where('user_type', 'admin')->where('status', 1)->count(),
            'locked' => (clone $q)->where('status', 2)->count(),
            'neverLogin' => (clone $q)->where('status', 1)->whereNull('last_login_at')->count(),
        ];
    }

    /** @return Collection<int, \Modules\Core\Models\ConfigLog> */
    public static function recentLogs(int $limit = 8): Collection
    {
        return \Modules\Core\Models\ConfigLog::where('entity', 'admin_user')->latest('id')->limit($limit)->get();
    }
}
