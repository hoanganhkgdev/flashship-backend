<?php
namespace Modules\Core\Services;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastMessage;
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
    public function sendOrderOffer(string $fcmToken, array $order, int $ttl = 45, string $offeredAt = ''): void
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
                'pickup_name'      => $order['sender_name'] ?? '',
                'pickup_phone'     => $order['pickup_phone'] ?? '',
                'customer_phone'   => $order['customer_phone'] ?? '',
                'pickup_lat'       => (string) ($order['pickup_lat'] ?? ''),
                'pickup_lng'       => (string) ($order['pickup_lng'] ?? ''),
                'delivery_address' => $order['delivery_address'] ?? '',
                'delivery_phone'   => $order['delivery_phone'] ?? '',
                'delivery_lat'     => (string) ($order['delivery_lat'] ?? ''),
                'delivery_lng'     => (string) ($order['delivery_lng'] ?? ''),
                'order_note'       => $order['order_note'] ?? '',
                'shipping_fee'     => (string) ($order['shipping_fee'] ?? 0),
                'discount_amount'  => (string) ($order['discount_amount'] ?? 0),
                'bonus_fee'        => (string) ($order['bonus_fee'] ?? 0),
                'payment_method'   => $order['payment_method'] ?? 'prepaid',
                'cod_amount'       => (string) ($order['cod_amount'] ?? 0),
                'ttl'              => (string) $ttl,
                'offered_at'       => $offeredAt ?: now()->toIso8601String(),
            ],
            'ttl' => $ttl,
        ]);
    }

    /**
     * Gửi offer đơn hàng đến nhiều tài xế cùng lúc (broadcast).
     */
    public function sendMulticastOrderOffer(array $tokens, array $order, int $ttl = 30, string $offeredAt = ''): void
    {
        if (empty($tokens)) return;

        $data = [
            'type'             => 'order_offer',
            'order_id'         => (string) $order['id'],
            'order_code'       => $order['code'] ?? '',
            'service_type'     => $order['service_type'] ?? 'delivery',
            'pickup_address'   => $order['pickup_address'] ?? '',
            'pickup_name'      => $order['sender_name'] ?? '',
            'pickup_phone'     => $order['pickup_phone'] ?? '',
            'customer_phone'   => $order['customer_phone'] ?? '',
            'pickup_lat'       => (string) ($order['pickup_lat'] ?? ''),
            'pickup_lng'       => (string) ($order['pickup_lng'] ?? ''),
            'delivery_address' => $order['delivery_address'] ?? '',
            'delivery_phone'   => $order['delivery_phone'] ?? '',
            'delivery_lat'     => (string) ($order['delivery_lat'] ?? ''),
            'delivery_lng'     => (string) ($order['delivery_lng'] ?? ''),
            'order_note'       => $order['order_note'] ?? '',
            'shipping_fee'     => (string) ($order['shipping_fee'] ?? 0),
            'discount_amount'  => (string) ($order['discount_amount'] ?? 0),
            'bonus_fee'        => (string) ($order['bonus_fee'] ?? 0),
            'payment_method'   => $order['payment_method'] ?? 'prepaid',
            'cod_amount'       => (string) ($order['cod_amount'] ?? 0),
            'ttl'              => (string) $ttl,
            'offered_at'       => $offeredAt ?: now()->toIso8601String(),
        ];

        foreach (array_chunk($tokens, 500) as $batch) {
            try {
                $message = MulticastMessage::new()
                    ->withNotification(Notification::create('Có đơn hàng mới!', "Từ: {$order['pickup_address']}"))
                    ->withData($data)
                    ->withAndroidConfig(AndroidConfig::fromArray([
                        'priority' => 'high',
                        'ttl'      => "{$ttl}s",
                    ]));

                $this->messaging->sendMulticast($message, $batch);
            } catch (\Throwable $e) {
                Log::error('[FCM] Multicast failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Thông báo tài xế khác rằng đơn đã được nhận.
     */
    public function sendOrderTakenByOther(string $fcmToken, string $orderCode): void
    {
        $this->send($fcmToken, [
            'title' => 'Đơn đã được nhận',
            'body'  => "Đơn $orderCode vừa được tài xế khác nhận.",
            'data'  => [
                'type'       => 'order_taken',
                'order_code' => $orderCode,
            ],
            'ttl' => 30,
        ]);
    }

    /**
     * Gửi thông báo huỷ tự động khi không tìm được tài xế.
     */
    public function sendNoDriverCancellation(string $fcmToken, string $orderCode): void
    {
        $this->send($fcmToken, [
            'title' => "Đơn $orderCode đã bị huỷ",
            'body'  => 'Không tìm được tài xế sau 10 phút. Vui lòng đặt lại.',
            'data'  => [
                'type'          => 'order_status',
                'order_code'    => $orderCode,
                'status'        => 'cancelled',
                'cancel_reason' => 'no_driver',
            ],
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
                        'headers' => [
                            'apns-priority'   => '10',
                            'apns-push-type'  => 'alert',
                        ],
                        'payload' => [
                            'aps' => [
                                'sound'             => 'default',
                                'badge'             => 1,
                                'content-available' => 1,
                            ],
                        ],
                    ])
                );

            $this->messaging->send($message);
        } catch (\Throwable $e) {
            $prev = $e->getPrevious();
            Log::error('[FCM] Send failed: ' . $e->getMessage(), [
                'token'    => substr($token, 0, 20) . '...',
                'class'    => get_class($e),
                'previous' => $prev ? get_class($prev) . ': ' . $prev->getMessage() : null,
                'payload'  => $payload,
            ]);
        }
    }
}
