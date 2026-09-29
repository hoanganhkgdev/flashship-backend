<?php
namespace Modules\Core\Services;

use App\Exceptions\OtpDeliveryException;
use App\Services\ZaloTokenService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
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

        $otp = PhoneOtp::create([
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

        $result = self::dispatch($phone, $code);
        if ($result !== 0) {
            // Mã chưa tới được người dùng — vô hiệu luôn; dòng vẫn tính vào
            // giới hạn 60 giây để không dò/spam nhiều số liên tục.
            $otp->update(['used' => true]);
            throw OtpDeliveryException::fromZaloError($result);
        }

        return $code;
    }

    /** Gửi tối đa một mã trong 60 giây, kể cả hai request tới đồng thời. */
    public static function sendThrottled(string $phone, string $type = 'register'): ?string
    {
        $key = 'otp-send-lock:' . hash('sha256', $type . '|' . $phone);
        $lock = Cache::lock($key, 10);

        if (!$lock->get()) return null;
        try {
            if (self::recentlySent($phone, $type)) return null;
            return self::send($phone, $type);
        } finally {
            $lock->release();
        }
    }

    /**
     * OTP chỉ gửi qua Zalo ZNS — cố ý không có SMS dự phòng: số phải gắn
     * tài khoản Zalo mới nhận được mã, chặn bớt số ảo/số dùng để phá.
     * Trả mã lỗi Zalo (0 = đã gửi), null = không gọi được Zalo.
     */
    private static function dispatch(string $phone, string $code): ?int
    {
        if (self::isTestPhone($phone)) return 0;

        $templateId = config('services.zalo_zns.otp_template_id');
        if (!$templateId) {
            Log::error("[OTP] Chưa cấu hình Zalo ZNS template → $phone");
            return null;
        }

        $result = ZaloTokenService::sendTemplate($phone, $templateId, ['otp' => $code]);
        if ($result === 0) {
            Log::info("[OTP] Zalo ZNS → $phone OK");
        } else {
            Log::warning("[OTP] Zalo ZNS thất bại → $phone (error=" . var_export($result, true) . ')');
        }

        return $result;
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

        // Tiêu thụ OTP trong transaction có row lock. Hai request dùng cùng
        // mã đến đồng thời thì request sau chỉ đọc được sau khi request đầu
        // commit used=true và bắt buộc thất bại.
        $consumed = DB::transaction(function () use ($phone, $code, $type) {
            $otp = PhoneOtp::where('phone', $phone)
                ->where('otp', $code)
                ->where('type', $type)
                ->where('used', false)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$otp) return false;
            $otp->update(['used' => true]);
            return true;
        });

        if (!$consumed) {
            RateLimiter::hit($rateLimitKey, 600);
            return false;
        }

        RateLimiter::clear($rateLimitKey);
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
