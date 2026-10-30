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
    /** Trả số điện thoại dạng 0901234567 nếu token hợp lệ, null nếu không. */
    public static function verifiedPhone(string $idToken): ?string
    {
        try {
            $token = self::auth()->verifyIdToken($idToken, true);
        } catch (\Throwable $e) {
            Log::warning('[FirebasePhone] Token không hợp lệ: ' . $e->getMessage());
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
