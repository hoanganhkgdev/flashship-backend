<?php
namespace Modules\Core\Services;

use Illuminate\Support\Facades\Log;

/**
 * Xác minh số điện thoại của khách bằng Firebase Phone Auth: app gửi và nhập
 * mã SMS với Firebase rồi đưa ID token lên đây. Backend chỉ kiểm tra token và
 * lấy số điện thoại đã được Firebase xác minh, không tự sinh hay gửi OTP.
 */
class FirebasePhoneVerifier
{
    /** Token xác minh OTP chỉ dùng được trong khoảng này kể từ lúc nhập mã. */
    public const MAX_AGE_SECONDS = 600;

    /** Trả số điện thoại dạng 0901234567 nếu token hợp lệ, null nếu không. */
    /** Chỉ dùng trong test: thay bước gọi Firebase, nhận id_token trả số đã xác minh (hoặc null). */
    public static ?\Closure $fake = null;

    public static function verifiedPhone(string $idToken): ?string
    {
        if (self::$fake && app()->runningUnitTests()) {
            $phone = (self::$fake)($idToken);

            return $phone === null ? null : self::normalize($phone);
        }
        try {
            $token = self::auth()->verifyIdToken($idToken, true);
        } catch (\Throwable $e) {
            Log::warning('[FirebasePhone] Token không hợp lệ: ' . $e->getMessage());
            return null;
        }

        // Token chứng minh sở hữu số điện thoại TẠI LÚC nhập OTP, nhưng còn hiệu lực cả giờ. Chỉ nhận token vừa
        // xác minh gần đây để token lộ ra (log, máy người khác) không dùng lại được để đặt mật khẩu/đăng ký.
        $authTime = (int) $token->claims()->get('auth_time', 0);
        if ($authTime > 0 && time() - $authTime > self::MAX_AGE_SECONDS) {
            Log::warning('[FirebasePhone] Token xác minh đã cũ', ['age' => time() - $authTime]);
            return null;
        }

        $phone = $token->claims()->get('phone_number');
        if (!is_string($phone) || $phone === '') return null;

        return self::normalize($phone);
    }

    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        // +84901234567 → 0901234567
        if (strlen($digits) === 11 && str_starts_with($digits, '84')) {
            $digits = '0' . substr($digits, 2);
        }
        return $digits;
    }

    private static function auth()
    {
        return RTDBService::factory()->createAuth();
    }
}
