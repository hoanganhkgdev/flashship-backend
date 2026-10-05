<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ZaloTokenService
{
    private const REFRESH_URL = 'https://oauth.zaloapp.com/v4/oa/access_token';
    private const SEND_URL    = 'https://business.openapi.zalo.me/message/template';

    private const ENC_PREFIX = 'enc:v1:';

    public static function encode(string $token): string
    {
        return self::ENC_PREFIX.Crypt::encryptString($token);
    }

    /** Giải mã token lưu trong DB; chấp nhận cả bản cũ chưa mã hóa. */
    public static function decode(?string $stored): ?string
    {
        if ($stored === null || ! str_starts_with($stored, self::ENC_PREFIX)) {
            return $stored;
        }

        try {
            return Crypt::decryptString(substr($stored, strlen(self::ENC_PREFIX)));
        } catch (\Throwable $e) {
            Log::error('[ZaloToken] Không giải mã được token (APP_KEY đã đổi?)');

            return null;
        }
    }

    private static function decodeRow(?object $row): ?object
    {
        if (! $row) {
            return null;
        }
        $row->access_token = self::decode($row->access_token);
        $row->refresh_token = self::decode($row->refresh_token);

        return $row;
    }

    /** Bản ghi token mới nhất, đã giải mã (chỉ dùng trong server, không đưa ra giao diện). */
    public static function row(): ?object
    {
        return self::decodeRow(DB::table('zalo_tokens')->orderByDesc('id')->first());
    }

    /** Lưu token (mã hóa) và chỉ giữ đúng một bản ghi. */
    public static function store(string $accessToken, string $refreshToken, int $expiresIn = 86400): void
    {
        $payload = [
            'access_token'      => self::encode($accessToken),
            'refresh_token'     => self::encode($refreshToken),
            'expires_at'        => now()->addSeconds($expiresIn),
            'last_error'        => null,
            'last_error_at'     => null,
            'last_refreshed_at' => now(),
            'updated_at'        => now(),
        ];

        DB::transaction(function () use ($payload) {
            $id = DB::table('zalo_tokens')->orderByDesc('id')->value('id');
            if ($id) {
                DB::table('zalo_tokens')->where('id', $id)->update($payload);
                DB::table('zalo_tokens')->where('id', '<>', $id)->delete();
            } else {
                DB::table('zalo_tokens')->insert($payload + ['created_at' => now()]);
            }
        });
    }

    private static function save(string $accessToken, string $refreshToken, int $expiresIn = 86400): void
    {
        self::store($accessToken, $refreshToken, $expiresIn);
    }

    private static function saveError(object $row, string $error): void
    {
        DB::table('zalo_tokens')->where('id', $row->id)->update([
            'last_error'    => $error,
            'last_error_at' => now(),
            'updated_at'    => now(),
        ]);
    }

    public static function getAccessToken(): ?string
    {
        $row = self::row();

        if (!$row) {
            // Seed từ .env nếu DB chưa có row
            $token   = config('services.zalo_zns.access_token');
            $refresh = config('services.zalo_zns.refresh_token');
            if ($token && $refresh) {
                self::save($token, $refresh, 86400);
                Log::info('[ZaloToken] Seeded token từ .env vào DB');
            }
            return $token ?: null;
        }

        // Chủ động refresh khi còn dưới 30 phút
        if ($row->expires_at && now()->addMinutes(30)->gte($row->expires_at)) {
            Log::info('[ZaloToken] Token sắp hết hạn, đang tự refresh...');
            if (self::refresh($row)) {
                return self::row()?->access_token ?? $row->access_token;
            }
            return $row->access_token;
        }

        return $row->access_token;
    }

    public static function refresh(?object $row = null): bool
    {
        $row          = $row ? self::decodeRow($row) : self::row();
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
                    isset($data['expires_in']) ? (int) $data['expires_in'] : 86400
                );
                Log::info('[ZaloToken] Refresh thành công — token mới có hiệu lực ' . ($data['expires_in'] ?? 86400) . 's');
                return true;
            }

            $errMsg = $res->body();
            Log::error('[ZaloToken] Refresh thất bại: ' . $errMsg);
            if ($row) self::saveError($row, $errMsg);
            self::notifyAdminByEmail($errMsg);
            return false;

        } catch (\Throwable $e) {
            Log::error('[ZaloToken] Exception: ' . $e->getMessage());
            if ($row) self::saveError($row, $e->getMessage());
            self::notifyAdminByEmail($e->getMessage());
            return false;
        }
    }

    /**
     * Gửi tin ZNS theo template. Trả mã lỗi của Zalo: 0 = gửi thành công,
     * số âm = Zalo từ chối (vd -118 số chưa có Zalo, -108 số không hợp lệ),
     * null = không gọi được Zalo (thiếu token, lỗi mạng).
     */
    public static function sendTemplate(string $phone, string $templateId, array $templateData, string $trackingId = ''): ?int
    {
        $zaloPhone = '84' . ltrim(preg_replace('/\D/', '', $phone), '0');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = self::getAccessToken();

            if (!$token) {
                Log::error('[ZaloToken] Không có access token');
                return null;
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

                if ($error === 0) return 0;

                if ($error === -124 && $attempt === 0) {
                    Log::warning('[ZaloToken] Token hết hạn (error -124), đang refresh...');
                    if (!self::refresh()) return null;
                    continue;
                }

                Log::warning('[ZNS] Gửi thất bại (error=' . $error . '): ' . $res->body());
                return is_int($error) ? $error : null;

            } catch (\Throwable $e) {
                Log::error('[ZNS] Exception: ' . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    private static function notifyAdminByEmail(string $reason): void
    {
        $adminEmail = config('services.zalo_zns.admin_email');
        if (!$adminEmail) return;

        try {
            Mail::raw(
                "Zalo ZNS refresh token thất bại\n\n" .
                "Thời gian: " . now()->format('d/m/Y H:i:s') . "\n" .
                "Lý do: {$reason}\n\n" .
                "Vào Admin Panel → Cài đặt → Zalo ZNS Token để cập nhật token mới.\n" .
                "Trong thời gian chưa fix, OTP sẽ KHÔNG gửi được tới người dùng!",
                fn ($msg) => $msg
                    ->to($adminEmail)
                    ->subject('[FlashShip] Zalo ZNS Token hết hạn — cần cập nhật')
            );
        } catch (\Throwable $e) {
            Log::warning('[ZaloToken] Không gửi được email: ' . $e->getMessage());
        }
    }
}
