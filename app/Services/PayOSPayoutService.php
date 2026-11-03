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
            $response = Http::connectTimeout(10)
                ->timeout(45)
                ->withHeaders(self::headers($referenceId, $signature))
                ->post(self::BASE_URL . '/v1/payouts', $body);

            $result = $response->json();

            if (($result['code'] ?? '') === '00') {
                Log::info("[PayOSPayout] Đã nhận lệnh: ref={$referenceId} amount={$amount} → {$accountNumber} state=".($result['data']['approvalState'] ?? '?'));
                return ['success' => true, 'data' => $result['data'] ?? []];
            }

            // Có mã lỗi rõ ràng từ PayOS = lệnh không được tạo. Không đọc được phản hồi (lỗi máy chủ, trang HTML...)
            // thì KHÔNG biết PayOS đã nhận hay chưa: coi như chưa rõ, không được chuyển lại theo cách khác.
            if (! is_array($result) || ! isset($result['code'])) {
                Log::error("[PayOSPayout] Phản hồi không đọc được: ref={$referenceId} http=".$response->status());
                return ['success' => false, 'ambiguous' => true, 'message' => 'PayOS không trả kết quả rõ ràng (HTTP '.$response->status().')'];
            }

            Log::error("[PayOSPayout] Thất bại: ref={$referenceId} code={$result['code']} desc=".($result['desc'] ?? ''));
            return ['success' => false, 'ambiguous' => false, 'message' => $result['desc'] ?? 'Lỗi không xác định'];
        } catch (\Throwable $e) {
            // Hết thời gian chờ / mất kết nối: PayOS có thể đã nhận và chuyển tiền.
            Log::error("[PayOSPayout] Exception: ref={$referenceId} " . $e->getMessage());
            return ['success' => false, 'ambiguous' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Lệnh chi theo mã tham chiếu của mình. Trả về null nếu không hỏi được PayOS (không kết luận gì),
     * ['found' => false] nếu PayOS chắc chắn không có lệnh này, hoặc ['found' => true, 'id', 'state'].
     *
     * @return array{found:bool,id?:?string,state?:?string}|null
     */
    public static function findByReference(string $referenceId): ?array
    {
        try {
            $response = Http::connectTimeout(10)->timeout(20)->withHeaders([
                'x-client-id' => config('services.payos_payout.client_id'),
                'x-api-key'   => config('services.payos_payout.api_key'),
            ])->get(self::BASE_URL . '/v1/payouts', ['referenceId' => $referenceId, 'limit' => 1]);

            $result = $response->json();
            if (($result['code'] ?? '') !== '00') {
                Log::warning("[PayOSPayout] Tra cứu lỗi: ref={$referenceId} code=".($result['code'] ?? 'http '.$response->status()));
                return null;
            }
            $payout = collect($result['data']['payouts'] ?? [])->firstWhere('referenceId', $referenceId);

            return $payout
                ? ['found' => true, 'id' => isset($payout['id']) ? (string) $payout['id'] : null, 'state' => isset($payout['approvalState']) ? strtoupper((string) $payout['approvalState']) : null]
                : ['found' => false];
        } catch (\Throwable $e) {
            Log::error("[PayOSPayout] Tra cứu exception: ref={$referenceId} " . $e->getMessage());
            return null;
        }
    }

    public static function getBalance(): ?array
    {
        try {
            $response = Http::connectTimeout(10)->timeout(30)->withHeaders([
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
