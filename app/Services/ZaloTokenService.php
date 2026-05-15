<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZaloTokenService
{
    private const REFRESH_URL = 'https://oauth.zaloapp.com/v4/oa/access_token';
    private const SEND_URL    = 'https://business.openapi.zalo.me/message/template';

    public static function getAccessToken(): ?string
    {
        $row = DB::table('zalo_tokens')->orderByDesc('id')->first();

        if ($row) {
            return $row->access_token;
        }

        // Fallback: seed from .env on first use
        $token   = config('services.zalo_zns.access_token');
        $refresh = config('services.zalo_zns.refresh_token');

        if ($token && $refresh) {
            DB::table('zalo_tokens')->insert([
                'access_token'  => $token,
                'refresh_token' => $refresh,
                'expires_at'    => null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        return $token;
    }

    public static function refresh(): bool
    {
        $row = DB::table('zalo_tokens')->orderByDesc('id')->first();

        $refreshToken = $row?->refresh_token ?? config('services.zalo_zns.refresh_token');
        $appId        = config('services.zalo_zns.app_id');
        $secretKey    = config('services.zalo_zns.secret_key');

        if (!$refreshToken || !$appId || !$secretKey) {
            Log::error('[ZaloToken] Thiếu app_id hoặc secret_key để refresh token');
            return false;
        }

        try {
            $res = Http::withHeaders(['secret_key' => $secretKey])
                ->asForm()
                ->post(self::REFRESH_URL, [
                    'app_id'        => $appId,
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);

            $data = $res->json();

            if (!empty($data['access_token'])) {
                DB::table('zalo_tokens')->insert([
                    'access_token'  => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? $refreshToken,
                    'expires_at'    => now()->addSeconds((int)($data['expires_in'] ?? 7776000)),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                Log::info('[ZaloToken] Token đã được refresh thành công');
                return true;
            }

            Log::error('[ZaloToken] Refresh thất bại: ' . $res->body());
            return false;
        } catch (\Throwable $e) {
            Log::error('[ZaloToken] Exception khi refresh: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gửi ZNS template. Nếu token hết hạn (-124), tự refresh và retry 1 lần.
     */
    public static function sendTemplate(string $phone, string $templateId, array $templateData, string $trackingId = ''): bool
    {
        $zaloPhone = '84' . ltrim(preg_replace('/\D/', '', $phone), '0');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = self::getAccessToken();

            if (!$token) {
                Log::error('[ZaloToken] Không có access token');
                return false;
            }

            try {
                $res = Http::withHeaders(['access_token' => $token])
                    ->post(self::SEND_URL, [
                        'phone'         => $zaloPhone,
                        'template_id'   => $templateId,
                        'template_data' => $templateData,
                        'tracking_id'   => $trackingId ?: 'zns_' . time(),
                    ]);

                $error = $res->json('error');

                if ($error === 0) {
                    return true;
                }

                // Token hết hạn → refresh rồi retry
                if ($error === -124 && $attempt === 0) {
                    Log::warning('[ZaloToken] Token hết hạn, đang refresh...');
                    if (!self::refresh()) {
                        return false;
                    }
                    continue;
                }

                Log::warning('[ZNS] Gửi thất bại (error=' . $error . '): ' . $res->body());
                return false;
            } catch (\Throwable $e) {
                Log::error('[ZNS] Exception: ' . $e->getMessage());
                return false;
            }
        }

        return false;
    }
}
