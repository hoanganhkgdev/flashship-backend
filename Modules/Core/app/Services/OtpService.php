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

        Log::info("[OTP] phone=$phone type=$type code=$code");

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

    public static function verify(string $phone, string $code, string $type = 'register'): bool
    {
        $otp = PhoneOtp::where('phone', $phone)
            ->where('otp', $code)
            ->where('type', $type)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$otp) return false;

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
