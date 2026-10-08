<?php

namespace App\Providers;

use App\Support\AdminAccess;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Models\User;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Web (php-fpm, www-data) và CLI (queue/cron, deploy) chạy bằng hai
        // user khác nhau: dùng chung một file log theo ngày thì file do bên
        // nào tạo trước, bên kia không ghi được. Tách file cho CLI. Luôn đặt
        // lại lúc chạy cho cả hai phía: config:cache chạy ở CLI sẽ lưu cả giá
        // trị này vào cache, nên không được dựa vào giá trị trong config.
        config(['logging.channels.daily.path' => storage_path(
            $this->app->runningInConsole() ? 'logs/laravel-cli.log' : 'logs/laravel.log'
        )]);
    }

    public function boot(): void
    {
        app()->setLocale('vi');

        // Ghi nhận lần đăng nhập gần nhất của tài khoản quản trị (trang quản trị trước đây không ghi).
        Event::listen(Login::class, function ($event) {
            $user = $event->user;
            if ($user instanceof User && AdminAccess::isAdminType($user)) {
                DB::table('users')->where('id', $user->id)->update(['last_login_at' => now()]);
            }
        });

        // Trước đây toàn bộ API (mọi module) không có rate-limit nào — kể cả
        // login mật khẩu và gửi/xác thực OTP đều dò được không giới hạn tốc
        // độ request. 120/phút/user(hoặc IP nếu chưa đăng nhập) đủ rộng cho
        // các app poll bình thường (đơn hàng, thông báo...), nhưng chặn được
        // brute-force hàng nghìn request/phút.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
    }
}
