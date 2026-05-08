<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gửi VoIP push qua APNs HTTP/2 dùng token-based auth (.p8 key).
 *
 * Yêu cầu trong .env:
 *   APNS_TEAM_ID=XXXXXXXXXX
 *   APNS_KEY_ID=XXXXXXXXXX
 *   APNS_BUNDLE_ID=vn.flashship.driver
 *   APNS_PRODUCTION=false
 *   APNS_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
 *
 * Hoặc để file tại: storage/app/apns/AuthKey_{KEY_ID}.p8
 */
class ApnsVoipService
{
    private static ?self $instance = null;

    private string $teamId;
    private string $keyId;
    private string $bundleId;
    private string $p8Key;
    private bool   $production;

    private function __construct()
    {
        $this->teamId     = config('services.apns.team_id');
        $this->keyId      = config('services.apns.key_id');
        $this->bundleId   = config('services.apns.bundle_id', 'com.flashship.driver');
        $this->production = config('services.apns.production', app()->isProduction());

        // Ưu tiên đọc từ env, fallback về file trên disk.
        $envKey = config('services.apns.private_key');
        if ($envKey) {
            $this->p8Key = str_replace('\n', "\n", $envKey);
        } else {
            $keyPath = storage_path('app/apns/AuthKey_' . $this->keyId . '.p8');
            $this->p8Key = file_get_contents($keyPath);
        }
    }

    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function sendOrderOffer(string $voipToken, array $order, int $ttl = 45, string $offeredAt = ''): void
    {
        $offeredAt = $offeredAt ?: now()->toIso8601String();
        $payload = [
            'aps'         => (object) [],
            'uuid'        => Str::uuid()->toString(),
            'nameCaller'  => 'FlashShip - Đơn hàng mới',
            'handle'      => $order['pickup_address'] ?? '',
            'type'        => 0,
            'duration'    => $ttl * 1000,
            'textAccept'  => 'Nhận đơn',
            'textDecline' => 'Từ chối',
            'extra'       => [
                'id'               => $order['id'],
                'code'             => $order['code'] ?? '',
                'service_type'     => $order['service_type'] ?? 'delivery',
                'status'           => 'pending',
                'pickup_address'   => $order['pickup_address'] ?? '',
                'delivery_address' => $order['delivery_address'] ?? '',
                'delivery_phone'   => $order['delivery_phone'] ?? '',
                'order_note'       => $order['order_note'] ?? '',
                'shipping_fee'     => $order['shipping_fee'] ?? 0,
                'payment_method'   => $order['payment_method'] ?? 'prepaid',
                'cod_amount'       => $order['cod_amount'] ?? 0,
                'offered_at'       => $offeredAt,
                'ttl'              => $ttl,
            ],
        ];

        $this->send($voipToken, $payload, $ttl);
    }

    private function send(string $token, array $payload, int $ttl): void
    {
        try {
            $jwt   = $this->generateJwt();
            $host  = $this->production ? 'api.push.apple.com' : 'api.sandbox.push.apple.com';
            $topic = $this->bundleId . '.voip';
            $url   = "https://{$host}/3/device/{$token}";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => [
                    "authorization: bearer {$jwt}",
                    "apns-topic: {$topic}",
                    "apns-push-type: voip",
                    "apns-expiration: " . (time() + $ttl + 5),
                    "apns-priority: 10",
                    "content-type: application/json",
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                Log::error('[APNs VoIP] Send failed', [
                    'status'  => $httpCode,
                    'body'    => $response,
                    'token'   => substr($token, 0, 16) . '...',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[APNs VoIP] Error: ' . $e->getMessage());
        }
    }

    private function generateJwt(): string
    {
        $header  = $this->base64url(json_encode(['alg' => 'ES256', 'kid' => $this->keyId]));
        $payload = $this->base64url(json_encode(['iss' => $this->teamId, 'iat' => time()]));
        $data    = "{$header}.{$payload}";

        $key = openssl_pkey_get_private($this->p8Key);
        openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);

        return "{$data}." . $this->base64url($this->derToRaw($signature));
    }

    private function derToRaw(string $der): string
    {
        $pos = 0;

        // Skip SEQUENCE tag
        $pos++;
        // Skip SEQUENCE length (handle multi-byte length)
        if (ord($der[$pos]) & 0x80) {
            $pos += (ord($der[$pos]) & 0x7F) + 1;
        } else {
            $pos++;
        }

        // Read INTEGER r
        $pos++;                         // skip 0x02 tag
        $rLen = ord($der[$pos++]);
        $r    = substr($der, $pos, $rLen);
        $pos += $rLen;

        // Read INTEGER s
        $pos++;                         // skip 0x02 tag
        $sLen = ord($der[$pos++]);
        $s    = substr($der, $pos, $sLen);

        // Normalize each to exactly 32 bytes (trim leading 0x00, then pad)
        $r = substr(str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT), -32);
        $s = substr(str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT), -32);

        return $r . $s;
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
