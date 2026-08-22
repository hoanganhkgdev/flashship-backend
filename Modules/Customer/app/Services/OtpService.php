<?php
namespace Modules\Customer\Services;

use App\Services\ZaloTokenService;
use App\Services\EsmsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Customer\Models\PhoneOtp;

class OtpService
{
    private static array $testPhones = ['0909123456', '84909123456'];

    // Chỉ nhận diện test-phone (OTP cố định 123456) ở local/testing — trước
    // đây không rào theo môi trường, nếu 1 số thật trùng danh sách này (hoặc
    // bị đăng ký để khai thác) thì production cũng nhận OTP cố định, bỏ qua
    // hoàn toàn rate-limit vì có mã đúng ngay từ đầu.
    private static function isTestPhone(string $phone): bool
    {
        if (!app()->environment(['local', 'testing'])) {
            return false;
        }
        return in_array(preg_replace('/\D/', '', $phone), self::$testPhones);
    }

    public static function send(string $phone, string $type): string
    {
        PhoneOtp::where('phone', $phone)
            ->where('type', $type)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $code = self::isTestPhone($phone) ? '123456' : str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        PhoneOtp::create([
            'phone'      => $phone,
            'code'       => $code,
            'type'       => $type,
            'expires_at' => now()->addMinutes(10),
        ]);

        // Không log mã OTP thật ở production — log file/hệ thống log tập
        // trung bị lộ là lấy được OTP hợp lệ, bỏ qua luôn rate-limit vì có
        // mã đúng ngay từ đầu (không cần dò).
        if (app()->environment(['local', 'testing'])) {
            Log::info("[OTP] phone=$phone type=$type code=$code");
        } else {
            Log::info("[OTP] phone=$phone type=$type — đã gửi");
        }

        self::dispatch($phone, $code);

        return $code;
    }

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


    // Trước đây không giới hạn số lần thử sai — resetPassword/verifyOtpAndRegister
    // là API công khai không cần đăng nhập, OTP 6 số dò được toàn bộ trong
    // 10 phút hiệu lực nếu không khoá (chiếm được tài khoản shop/customer bất
    // kỳ chỉ cần biết SĐT). Khoá theo phone+type, không theo IP, để không bị
    // đổi IP bypass.
    public static function verify(string $phone, string $code, string $type): bool
    {
        $rateLimitKey = "otp-verify:{$type}:{$phone}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return false;
        }

        $otp = PhoneOtp::where('phone', $phone)
            ->where('code', $code)
            ->where('type', $type)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$otp) {
            RateLimiter::hit($rateLimitKey, 600);
            return false;
        }

        RateLimiter::clear($rateLimitKey);
        $otp->update(['used_at' => now()]);
        return true;
    }

    public static function recentlySent(string $phone, string $type): bool
    {
        return PhoneOtp::where('phone', $phone)
            ->where('type', $type)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->exists();
    }
}
