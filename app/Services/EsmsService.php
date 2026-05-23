<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EsmsService
{
    private const SEND_URL = 'https://rest.esms.vn/MainService.svc/json/SendMultipleMessage_V4_post_json/';

    public static function sendOtp(string $phone, string $code): bool
    {
        $apiKey     = config('services.esms.api_key');
        $secretKey  = config('services.esms.secret_key');
        $brandname  = config('services.esms.brandname', 'Verify');
        $content    = config('services.esms.otp_template', 'Ma OTP cua ban la: {code}');

        if (!$apiKey || !$secretKey) {
            Log::warning('[eSMS] Chưa cấu hình API key');
            return false;
        }

        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '84') && strlen($digits) === 11) {
            $digits = '0' . substr($digits, 2);
        }
        $phone = $digits;
        $content = str_replace('{code}', $code, $content);

        try {
            $res  = Http::post(self::SEND_URL, [
                'ApiKey'    => $apiKey,
                'Content'   => $content,
                'Phone'     => $phone,
                'SecretKey' => $secretKey,
                'SmsType'   => '8',
                'IsUnicode' => '0',
            ]);

            $result = $res->json('CodeResult');

            if ($result === '100') {
                Log::info("[eSMS] Gửi OTP thành công → {$phone}");
                return true;
            }

            Log::warning("[eSMS] Gửi thất bại (CodeResult={$result}): " . $res->body());
            return false;
        } catch (\Throwable $e) {
            Log::error('[eSMS] Exception: ' . $e->getMessage());
            return false;
        }
    }
}
