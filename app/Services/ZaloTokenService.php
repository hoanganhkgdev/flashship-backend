<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZaloTokenService
{
    private const REFRESH_URL = 'https://oauth.zaloapp.com/v4/oa/access_token';
    private const SEND_URL    = 'https://business.openapi.zalo.me/message/template';

    private static function row(): ?object
    {
        return DB::table('zalo_tokens')->orderByDesc('id')->first();
    }

    private static function save(string $accessToken, string $refreshToken, int $expiresIn = 86400): void
    {
        $row = self::row();
        $payload = [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at'    => now()->addSeconds($expiresIn),
            'updated_at'    => now(),
        ];

        if ($row) {
            DB::table('zalo_tokens')->where('id', $row->id)->update($payload);
        } else {
            DB::table('zalo_tokens')->insert($payload + ['created_at' => now()]);
        }
    }

    public static function getAccessToken(): ?string
    {
        $row = self::row();

        if (!$row) {
            $token   = config('services.zalo_zns.access_token');
            $refresh = config('services.zalo_zns.refresh_token');
            if ($token && $refresh) {
                self::save($token, $refresh);
            }
            return $token;
        }

        // Chủ động refresh khi còn dưới 30 phút
        if ($row->expires_at && now()->addMinutes(30)->gte($row->expires_at)) {
            self::refresh($row);
            return self::row()?->access_token ?? $row->access_token;
        }

        return $row->access_token;
    }

    public static function refresh(?object $row = null): bool
    {
        $row          = $row ?? self::row();
        $refreshToken = $row?->refresh_token ?? config('services.zalo_zns.refresh_token');
        $appId        = config('services.zalo_zns.app_id');
        $secretKey    = config('services.zalo_zns.secret_key');

        if (!$refreshToken || !$appId || !$secretKey) {
            Log::error('[ZaloToken] Thiếu app_id hoặc secret_key để refresh token');
            return false;
        }

        try {
            $res  = Http::withHeaders(['secret_key' => $secretKey])
                ->asForm()
                ->post(self::REFRESH_URL, [
                    'app_id'        => $appId,
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);
            $data = $res->json();

            if (!empty($data['access_token'])) {
                self::save(
                    $data['access_token'],
                    $data['refresh_token'] ?? $refreshToken,
                    isset($data['expires_in']) ? (int) $data['expires_in'] : 7776000
                );
                Log::info('[ZaloToken] Refresh thành công');
                return true;
            }

            Log::error('[ZaloToken] Refresh thất bại: ' . $res->body());
            return false;
        } catch (\Throwable $e) {
            Log::error('[ZaloToken] Exception: ' . $e->getMessage());
            return false;
        }
    }

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
                $res   = Http::withHeaders(['access_token' => $token])
                    ->post(self::SEND_URL, [
                        'phone'         => $zaloPhone,
                        'template_id'   => $templateId,
                        'template_data' => $templateData,
                        'tracking_id'   => $trackingId ?: 'zns_' . time(),
                    ]);
                $error = $res->json('error');

                if ($error === 0) return true;

                if ($error === -124 && $attempt === 0) {
                    Log::warning('[ZaloToken] Token hết hạn, đang refresh...');
                    if (!self::refresh()) return false;
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
