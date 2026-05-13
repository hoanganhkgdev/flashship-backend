<?php
namespace Modules\Customer\Services;

use Illuminate\Support\Facades\Log;
use Modules\Customer\Models\PhoneOtp;

class OtpService
{
    // Test phone numbers that always receive code 123456 (remove before going live)
    private static array $testPhones = ['0909123456', '84909123456'];

    private static function isTestPhone(string $phone): bool
    {
        return in_array(preg_replace('/\D/', '', $phone), self::$testPhones);
    }

    public static function send(string $phone, string $type): string
    {
        // Invalidate any previous unused OTPs for this phone+type
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

        // Log OTP for development. Replace with real SMS service in production:
        // SmsService::send($phone, "Mã OTP FlashShip: $code. Hết hạn sau 10 phút.");
        Log::info("[OTP] phone=$phone type=$type code=$code");

        return $code;
    }

    public static function verify(string $phone, string $code, string $type): bool
    {
        $otp = PhoneOtp::where('phone', $phone)
            ->where('code', $code)
            ->where('type', $type)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$otp) return false;

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
