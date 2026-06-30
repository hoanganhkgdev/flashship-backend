<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EsmsService
{
    private const BASE_URL = 'http://rest.esms.vn/MainService.svc/json';

    private static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '84')) {
            return '0' . substr($digits, 2);
        }
        return $digits;
    }

    public static function sendOtp(string $phone, string $code): bool
    {
        $apiKey    = config('services.esms.api_key');
        $secretKey = config('services.esms.secret_key');

        if (!$apiKey || !$secretKey) {
            Log::warning('[ESMS] Chưa cấu hình API key/secret key');
            return false;
        }

        $content = "Ma OTP FlashShip cua ban la: {$code}. Het han sau 10 phut.";

        try {
            $response = Http::asForm()->post(self::BASE_URL . '/SendMultipleMessage_V4_post/', [
                'Phone'      => self::normalizePhone($phone),
                'Content'    => $content,
                'ApiKey'     => $apiKey,
                'SecretKey'  => $secretKey,
                'IsUnicode'  => 0,
                'SmsType'    => 2,
            ]);

            $result = $response->json();
            $code   = $result['CodeResult'] ?? null;

            if ($code === '100') {
                Log::info("[ESMS] Gửi OTP thành công → {$phone}");
                return true;
            }

            Log::error("[ESMS] Gửi OTP thất bại → {$phone}: " . json_encode($result));
            return false;
        } catch (\Throwable $e) {
            Log::error("[ESMS] Exception → {$phone}: " . $e->getMessage());
            return false;
        }
    }

    public static function getBalance(): ?float
    {
        $apiKey    = config('services.esms.api_key');
        $secretKey = config('services.esms.secret_key');

        try {
            $response = Http::get(self::BASE_URL . "/GetBalance/{$apiKey}/{$secretKey}");
            $result   = $response->json();
            return isset($result['Balance']) ? (float) $result['Balance'] : null;
        } catch (\Throwable $e) {
            Log::error('[ESMS] getBalance error: ' . $e->getMessage());
            return null;
        }
    }
}
