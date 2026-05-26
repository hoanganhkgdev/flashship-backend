<?php
namespace Modules\Core\Services;

use App\Services\EsmsService;
use App\Services\ZaloTokenService;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\PhoneOtp;

class OtpService
{
    private static array $testPhones = ['0909123456', '84909123456'];

    private static function isTestPhone(string $phone): bool
    {
        return in_array(preg_replace('/\D/', '', $phone), self::$testPhones);
    }

    public static function send(string $phone): string
    {
        PhoneOtp::where('phone', $phone)->where('used', false)->update(['used' => true]);

        $code = self::isTestPhone($phone)
            ? '123456'
            : str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        PhoneOtp::create([
            'phone'      => $phone,
            'otp'        => $code,
            'expires_at' => now()->addMinutes(10),
        ]);

        Log::info("[OTP] phone=$phone code=$code");

        self::dispatch($phone, $code);

        return $code;
    }

    private static function dispatch(string $phone, string $code): void
    {
        if (self::isTestPhone($phone)) return;

        $templateId = config('services.zalo_zns.otp_template_id');

        if ($templateId) {
            $sent = ZaloTokenService::sendTemplate($phone, $templateId, [
                'otp'    => $code,
                'expiry' => '10 phút',
            ]);

            if ($sent) {
                Log::info("[OTP] Zalo ZNS → $phone OK");
                return;
            }

            Log::warning("[OTP] Zalo ZNS thất bại, fallback eSMS → $phone");
        }

        EsmsService::sendOtp($phone, $code);
    }


    public static function verify(string $phone, string $code): bool
    {
        $otp = PhoneOtp::where('phone', $phone)
            ->where('otp', $code)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$otp) return false;

        $otp->update(['used' => true]);
        return true;
    }

    public static function recentlySent(string $phone): bool
    {
        return PhoneOtp::where('phone', $phone)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->exists();
    }
}
