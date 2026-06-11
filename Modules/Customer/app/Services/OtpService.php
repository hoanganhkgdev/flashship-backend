<?php
namespace Modules\Customer\Services;

use App\Services\ZaloTokenService;
use Illuminate\Support\Facades\Log;
use Modules\Customer\Models\PhoneOtp;

class OtpService
{
    private static array $testPhones = ['0909123456', '84909123456'];

    private static function isTestPhone(string $phone): bool
    {
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

        Log::info("[OTP] phone=$phone type=$type code=$code");

        self::dispatch($phone, $code);

        return $code;
    }

    private static function dispatch(string $phone, string $code): void
    {
        if (self::isTestPhone($phone)) return;

        $templateId = config('services.zalo_zns.otp_template_id');

        if (!$templateId) {
            Log::warning("[OTP] Chưa cấu hình Zalo ZNS template → $phone");
            return;
        }

        $sent = ZaloTokenService::sendTemplate($phone, $templateId, [
            'otp'    => $code,
            'expiry' => '10 phút',
        ]);

        if ($sent) {
            Log::info("[OTP] Zalo ZNS → $phone OK");
        } else {
            Log::warning("[OTP] Zalo ZNS thất bại → $phone");
        }
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
