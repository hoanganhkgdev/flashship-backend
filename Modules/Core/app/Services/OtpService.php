<?php
namespace Modules\Core\Services;

use Illuminate\Support\Facades\Http;
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

        Log::info("[Driver OTP] phone=$phone code=$code");

        self::sendZaloZns($phone, $code);

        return $code;
    }

    private static function sendZaloZns(string $phone, string $code): void
    {
        $accessToken = config('services.zalo_zns.access_token');
        $templateId  = config('services.zalo_zns.otp_template_id');

        if (!$accessToken || !$templateId) return;

        $zaloPhone = '84' . ltrim(preg_replace('/\D/', '', $phone), '0');

        try {
            $res = Http::withHeaders(['access_token' => $accessToken])
                ->post('https://business.openapi.zalo.me/message/template', [
                    'phone'         => $zaloPhone,
                    'template_id'   => $templateId,
                    'template_data' => ['otp' => $code],
                    'tracking_id'   => 'driver_otp_' . time(),
                ]);

            if (!$res->successful() || ($res->json('error') !== 0)) {
                Log::warning('[ZNS Driver] Gửi OTP thất bại: ' . $res->body());
            }
        } catch (\Throwable $e) {
            Log::error('[ZNS Driver] Exception: ' . $e->getMessage());
        }
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
