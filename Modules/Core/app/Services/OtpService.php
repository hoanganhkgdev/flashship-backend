<?php
namespace Modules\Core\Services;

use App\Services\EsmsService;
use App\Services\ZaloTokenService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Models\PhoneOtp;

class OtpService
{
    private static array $testPhones = ['0909123456', '84909123456'];

    // Chỉ nhận diện test-phone (OTP cố định 123456) ở local/testing — tránh
    // 1 số thật trùng danh sách này ở production nhận OTP cố định, bỏ qua
    // rate-limit vì có mã đúng ngay từ đầu. Cùng fix áp cho
    // Modules/Customer/app/Services/OtpService.php (bug giống hệt).
    private static function isTestPhone(string $phone): bool
    {
        if (!app()->environment(['local', 'testing'])) {
            return false;
        }
        return in_array(preg_replace('/\D/', '', $phone), self::$testPhones);
    }

    public static function send(string $phone, string $type = 'register'): string
    {
        PhoneOtp::where('phone', $phone)->where('type', $type)
            ->where('used', false)->update(['used' => true]);

        $code = self::isTestPhone($phone)
            ? '123456'
            : str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        PhoneOtp::create([
            'phone'      => $phone,
            'otp'        => $code,
            'type'       => $type,
            'expires_at' => now()->addMinutes(10),
        ]);

        // Không log mã OTP thật ở production — xem lý do ở Customer\OtpService.
        if (app()->environment(['local', 'testing'])) {
            Log::info("[OTP] phone=$phone type=$type code=$code");
        } else {
            Log::info("[OTP] phone=$phone type=$type — đã gửi");
        }

        self::dispatch($phone, $code);

        return $code;
    }

    /**
     * Zalo ZNS trước, thất bại thì fallback SMS (eSMS) — đồng bộ với luồng
     * OTP của customer; trước đây driver chỉ có Zalo, Zalo trượt là mất mã.
     */
    private static function dispatch(string $phone, string $code): void
    {
        if (self::isTestPhone($phone)) return;

        $templateId = config('services.zalo_zns.otp_template_id');
        $zaloSent   = false;

        if ($templateId) {
            $zaloSent = ZaloTokenService::sendTemplate($phone, $templateId, [
                'otp'    => $code,
                'expiry' => '10 phút',
            ]);
        } else {
            Log::warning("[OTP] Chưa cấu hình Zalo ZNS template → $phone");
        }

        if ($zaloSent) {
            Log::info("[OTP] Zalo ZNS → $phone OK");
            return;
        }

        Log::warning("[OTP] Zalo ZNS thất bại → $phone, fallback sang SMS");

        $smsSent = EsmsService::sendOtp($phone, $code);
        if ($smsSent) {
            Log::info("[OTP] SMS fallback → $phone OK");
        } else {
            Log::error("[OTP] Cả Zalo và SMS đều thất bại → $phone");
        }
    }

    // Trước đây không giới hạn số lần thử sai — OTP 6 số + endpoint verify ở
    // 1 số nơi (register, forgot-password) không cần đăng nhập, dò được toàn
    // bộ trong 10 phút hiệu lực nếu không khoá. Khoá theo phone+type, không
    // theo IP, để không bị đổi IP bypass.
    public static function verify(string $phone, string $code, string $type = 'register'): bool
    {
        $rateLimitKey = "otp-verify:{$type}:{$phone}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return false;
        }

        $otp = PhoneOtp::where('phone', $phone)
            ->where('otp', $code)
            ->where('type', $type)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$otp) {
            RateLimiter::hit($rateLimitKey, 600);
            return false;
        }

        RateLimiter::clear($rateLimitKey);
        $otp->update(['used' => true]);
        return true;
    }

    public static function recentlySent(string $phone, string $type = 'register'): bool
    {
        return PhoneOtp::where('phone', $phone)
            ->where('type', $type)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->exists();
    }
}
