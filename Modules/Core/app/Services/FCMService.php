<?php
namespace Modules\Core\Services;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;

class FCMService
{
    private static ?self $instance = null;
    private $messaging;

    private function __construct()
    {
        $factory = (new Factory)->withServiceAccount(
            storage_path('app/firebase-service-account.json')
        );
        $this->messaging = $factory->createMessaging();
    }

    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Gửi offer đơn hàng đến tài xế.
     */
    public function sendOrderOffer(string $fcmToken, array $order, int $ttl = 20): void
    {
        $this->send($fcmToken, [
            'title'   => 'Có đơn hàng mới!',
            'body'    => "Từ: {$order['pickup_address']}",
            'data'    => [
                'type'             => 'order_offer',
                'order_id'         => (string) $order['id'],
                'order_code'       => $order['code'] ?? '',
                'service_type'     => $order['service_type'] ?? 'delivery',
                'pickup_address'   => $order['pickup_address'] ?? '',
                'delivery_address' => $order['delivery_address'] ?? '',
                'delivery_phone'   => $order['delivery_phone'] ?? '',
                'order_note'       => $order['order_note'] ?? '',
                'shipping_fee'     => (string) ($order['shipping_fee'] ?? 0),
                'payment_method'   => $order['payment_method'] ?? 'prepaid',
                'cod_amount'       => (string) ($order['cod_amount'] ?? 0),
                'ttl'              => (string) $ttl,
            ],
            'ttl' => $ttl,
        ]);
    }

    /**
     * Gửi thông báo cập nhật trạng thái đơn cho khách hàng.
     */
    public function sendOrderStatusUpdate(string $fcmToken, string $orderCode, string $status): void
    {
        $statusLabel = match ($status) {
            'assigned'   => 'Tài xế đã nhận đơn',
            'processing' => 'Tài xế đang lấy hàng',
            'on_the_way' => 'Đơn hàng đang được giao',
            'completed'  => 'Đơn hàng đã giao thành công',
            'cancelled'  => 'Đơn hàng đã bị hủy',
            default      => 'Cập nhật đơn hàng',
        };

        $this->send($fcmToken, [
            'title' => "Đơn $orderCode",
            'body'  => $statusLabel,
            'data'  => [
                'type'       => 'order_status',
                'order_code' => $orderCode,
                'status'     => $status,
            ],
        ]);
    }

    private function send(string $token, array $payload): void
    {
        try {
            $ttl = $payload['ttl'] ?? 3600;

            $message = CloudMessage::withTarget('token', $token)
                ->withNotification(
                    Notification::create($payload['title'], $payload['body'])
                )
                ->withData($payload['data'] ?? [])
                ->withAndroidConfig(
                    AndroidConfig::fromArray([
                        'priority' => 'high',
                        'ttl'      => "{$ttl}s",
                    ])
                )
                ->withApnsConfig(
                    ApnsConfig::fromArray([
                        'headers' => ['apns-priority' => '10'],
                        'payload' => ['aps' => ['sound' => 'default', 'badge' => 1]],
                    ])
                );

            $this->messaging->send($message);
        } catch (\Throwable $e) {
            Log::error('[FCM] Send failed: ' . $e->getMessage(), [
                'token'   => substr($token, 0, 20) . '...',
                'payload' => $payload,
            ]);
        }
    }
}
