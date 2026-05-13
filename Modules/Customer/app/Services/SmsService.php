<?php
namespace Modules\Customer\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private const API_URL = 'https://api.speedsms.vn/index.php/sms/send';

    public static function send(string $phone, string $message): bool
    {
        $accessToken = config('services.speedsms.access_token');

        // Nếu chưa cấu hình → chỉ log (môi trường dev)
        if (empty($accessToken)) {
            Log::info("[SMS-DEV] To: $phone | $message");
            return true;
        }

        $phone = self::normalizePhone($phone);

        try {
            $response = Http::timeout(10)
                ->withBasicAuth($accessToken, ':')
                ->post(self::API_URL, [
                    'to'      => [$phone],
                    'content' => $message,
                    'type'    => config('services.speedsms.type', 8), // 8 = OTP
                    'sender'  => config('services.speedsms.sender', 'SpeedSMS'),
                ]);

            $result = $response->json();

            if (($result['status'] ?? '') === 'success') {
                return true;
            }

            Log::error('[SpeedSMS] Gửi SMS thất bại', [
                'phone'  => $phone,
                'result' => $result,
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('[SpeedSMS] Lỗi kết nối: ' . $e->getMessage(), ['phone' => $phone]);
            return false;
        }
    }

    // Chuẩn hóa SĐT về dạng 0xxxxxxxxx
    private static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '84')) {
            $phone = '0' . substr($phone, 2);
        }
        return $phone;
    }
}
