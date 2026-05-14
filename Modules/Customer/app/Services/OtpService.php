<?php
namespace Modules\Customer\Services;

use Illuminate\Support\Facades\Http;
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

        Log::info("[OTP] phone=$phone type=$type code=$code");

        self::sendZaloZns($phone, $code);

        return $code;
    }

    private static function sendZaloZns(string $phone, string $code): void
    {
        $accessToken = config('services.zalo_zns.access_token');
        $templateId  = config('services.zalo_zns.otp_template_id');

        if (!$accessToken || !$templateId) return;

        // 09xx → 849xx (định dạng Zalo ZNS)
        $zaloPhone = '84' . ltrim(preg_replace('/\D/', '', $phone), '0');

        try {
            $res = Http::withHeaders(['access_token' => $accessToken])
                ->post('https://business.openapi.zalo.me/message/template', [
                    'phone'         => $zaloPhone,
                    'template_id'   => $templateId,
                    'template_data' => ['otp' => $code],
                    'tracking_id'   => 'otp_' . time(),
                ]);

            if (!$res->successful() || ($res->json('error') !== 0)) {
                Log::warning('[ZNS] Gửi OTP thất bại: ' . $res->body());
            }
        } catch (\Throwable $e) {
            Log::error('[ZNS] Exception: ' . $e->getMessage());
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
