<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayOSPayoutService
{
    private const BASE_URL = 'https://api-merchant.payos.vn';

    private static function headers(string $idempotencyKey, string $signature): array
    {
        return [
            'x-client-id'      => config('services.payos_payout.client_id'),
            'x-api-key'        => config('services.payos_payout.api_key'),
            'x-idempotency-key' => $idempotencyKey,
            'x-signature'      => $signature,
            'Content-Type'     => 'application/json',
        ];
    }

    private static function sign(array $data): string
    {
        ksort($data);
        $query = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        return hash_hmac('sha256', $query, config('services.payos_payout.checksum_key'));
    }

    public static function createPayout(
        string $referenceId,
        int    $amount,
        string $description,
        string $bankCode,
        string $accountNumber,
    ): array {
        $body = [
            'referenceId'     => $referenceId,
            'amount'          => $amount,
            'description'     => mb_substr($description, 0, 50),
            'toBin'           => $bankCode,
            'toAccountNumber' => $accountNumber,
        ];

        $signature = self::sign($body);

        try {
            $response = Http::withHeaders(self::headers($referenceId, $signature))
                ->post(self::BASE_URL . '/v1/payouts', $body);

            $result = $response->json();

            if (($result['code'] ?? '') === '00') {
                Log::info("[PayOSPayout] Thành công: ref={$referenceId} amount={$amount} → {$accountNumber}");
                return ['success' => true, 'data' => $result['data'] ?? []];
            }

            Log::error("[PayOSPayout] Thất bại: ref={$referenceId} code={$result['code']} desc={$result['desc']}");
            return ['success' => false, 'message' => $result['desc'] ?? 'Lỗi không xác định'];
        } catch (\Throwable $e) {
            Log::error("[PayOSPayout] Exception: ref={$referenceId} " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function getBalance(): ?array
    {
        try {
            $response = Http::withHeaders([
                'x-client-id' => config('services.payos_payout.client_id'),
                'x-api-key'   => config('services.payos_payout.api_key'),
            ])->get(self::BASE_URL . '/v1/payouts-account/balance');

            $result = $response->json();
            if (($result['code'] ?? '') === '00') {
                return $result['data'];
            }
            return null;
        } catch (\Throwable $e) {
            Log::error("[PayOSPayout] getBalance error: " . $e->getMessage());
            return null;
        }
    }
}
