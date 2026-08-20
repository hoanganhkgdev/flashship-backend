<?php

namespace App\Filament\Traits;

use Modules\Core\Models\User;

/**
 * Giới hạn CHỈ admin đầy đủ (không subadmin/city_manager/call_center) được
 * truy cập — dùng cho các resource động tới tiền/điểm thật (ví, công nợ,
 * rút tiền, giá cước) hoặc quyền quản trị (tài khoản admin). Trước đây các
 * resource này chỉ chặn call_center/city_manager (qua canAccess() +
 * HideFromCityManager riêng lẻ) — subadmin lọt qua, có quyền y hệt admin ở
 * chính những thao tác nhạy cảm nhất trong hệ thống.
 */
trait RestrictToFullAdmin
{
    public static function canAccess(): bool
    {
        return auth()->user()?->user_type === User::ROLE_ADMIN;
    }
}
