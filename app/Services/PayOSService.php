<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PayOS\PayOS;

class PayOSService
{
    private static function client(): PayOS
    {
        return new PayOS(
            config('services.payos.client_id'),
            config('services.payos.api_key'),
            config('services.payos.checksum_key'),
        );
    }

    /**
     * Tạo payment link. Trả về ['qr_code', 'checkout_url', 'payment_link_id'] hoặc null.
     */
    public static function createPayment(
        int    $orderCode,
        int    $amount,
        string $description,
        string $type = 'topup',
    ): ?array {
        try {
            $baseUrl    = config('app.url');
            $returnUrl  = "{$baseUrl}/payment/return?type={$type}";
            $cancelUrl  = "{$baseUrl}/payment/cancel?type={$type}";

            $data = self::client()->createPaymentLink([
                'orderCode'   => $orderCode,
                'amount'      => $amount,
                'description' => mb_substr($description, 0, 25),
                'returnUrl'   => $returnUrl,
                'cancelUrl'   => $cancelUrl,
            ]);

            return [
                'qr_code'          => $data['qrCode']         ?? null,
                'checkout_url'     => $data['checkoutUrl']    ?? null,
                'payment_link_id'  => $data['paymentLinkId']  ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('[PayOS] createPayment error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Lấy trạng thái payment. Trả về 'PAID' | 'CANCELLED' | 'PENDING' | null nếu lỗi.
     */
    public static function getPaymentStatus(int $orderCode): ?string
    {
        try {
            $data   = self::client()->getPaymentLinkInformation($orderCode);
            return strtoupper($data['status'] ?? 'PENDING');
        } catch (\Throwable $e) {
            Log::error('[PayOS] getPaymentStatus error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Huỷ payment link còn PENDING trên PayOS — gọi trước khi tạo QR mới cho
     * cùng 1 khoản (nợ/nạp ví), tránh QR cũ vẫn quét trả tiền được nhưng hệ
     * thống không ghi nhận vào đâu (tiền mất mà không được cộng công nợ/ví).
     */
    public static function cancelPayment(int $orderCode, string $reason = 'Tạo QR mới'): bool
    {
        try {
            self::client()->paymentRequests->cancel($orderCode, $reason);
            return true;
        } catch (\Throwable $e) {
            // Link đã PAID/CANCELLED từ trước sẽ ném lỗi ở đây — bỏ qua vì
            // mục tiêu (không còn dùng được nữa) đã đạt.
            Log::info('[PayOS] cancelPayment (có thể đã xử lý trước đó): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify webhook signature. Trả về true nếu hợp lệ.
     */
    public static function verifyWebhook(array $data): bool
    {
        try {
            self::client()->verifyPaymentWebhookData($data);
            return true;
        } catch (\Throwable $e) {
            Log::warning('[PayOS] Invalid webhook signature: ' . $e->getMessage());
            return false;
        }
    }
}
