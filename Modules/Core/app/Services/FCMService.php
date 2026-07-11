<?php
namespace Modules\Core\Services;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\Messaging\AuthenticationError;
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

    private static function resetInstance(): void
    {
        self::$instance = new self();
    }

    /**
     * Wake-up signal để app resume và đọc RTDB offer.
     */
    public function sendDriverWakeUp(string $fcmToken, int $orderId, string $orderCode = '', string $pickupAddress = '', ?int $expiresAt = null): void
    {
        // Data-only message: Flutter background handler tạo notification
        // → chỉ 1 notification duy nhất, không bị duplicate từ FCM system
        try {
            $message = CloudMessage::withTarget('token', $fcmToken)
                ->withData([
                    'type'       => 'order_offer',
                    'order_id'   => (string) $orderId,
                    'order_code' => (string) $orderCode,
                    // App dùng để tự tắt banner đúng lúc offer hết hạn
                    'expires_at' => (string) ($expiresAt ?? (time() + 25)),
                ])
                ->withAndroidConfig(AndroidConfig::fromArray([
                    'priority' => 'high',
                    'ttl'      => '25s',
                ]))
                ->withApnsConfig(ApnsConfig::fromArray([
                    'headers' => [
                        'apns-priority'  => '10',
                        'apns-push-type' => 'background',
                    ],
                    'payload' => ['aps' => [
                        'content-available' => 1,
                    ]],
                ]));
            try {
                $this->messaging->send($message);
            } catch (AuthenticationError $e) {
                self::resetInstance(); self::$instance->messaging->send($message);
            }
        } catch (\Throwable $e) {
            Log::error('[FCM] sendDriverWakeUp failed: ' . $e->getMessage());
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
     * Thông báo cho khách biết hệ thống đang tìm tài xế.
     */
    public function sendSearchingDriver(string $fcmToken, string $orderCode): void
    {
        $this->send($fcmToken, [
            'title' => "Đơn $orderCode",
            'body'  => 'Đang tìm tài xế gần bạn...',
            'data'  => ['type' => 'dispatch_searching', 'order_code' => $orderCode],
        ]);
    }

    /**
     * Thông báo cho khách khi hệ thống mở rộng tìm kiếm sang bán kính lớn hơn.
     */
    public function sendExpandingSearch(string $fcmToken, string $orderCode): void
    {
        $this->send($fcmToken, [
            'title' => "Đơn $orderCode",
            'body'  => 'Đang mở rộng tìm kiếm tài xế...',
            'data'  => ['type' => 'dispatch_expanding', 'order_code' => $orderCode],
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

    /**
     * Gửi thông báo thông thường đến nhiều token cùng lúc.
     */
    public function broadcast(array $tokens, string $title, string $body, array $data = []): array
    {
        $sent = $failed = 0;
        $notif   = Notification::create($title, $body);
        $payload = array_merge(['type' => 'broadcast', 'title' => $title, 'body' => $body], $data);
        $android = AndroidConfig::fromArray(['priority' => 'high', 'ttl' => '3600s']);
        $apns    = ApnsConfig::fromArray([
            'headers' => ['apns-priority' => '10', 'apns-push-type' => 'alert'],
            'payload' => ['aps' => ['sound' => 'default', 'badge' => 1, 'content-available' => 1]],
        ]);

        foreach (array_chunk($tokens, 500) as $batch) {
            try {
                $messages = array_map(
                    fn (string $token) => CloudMessage::withTarget('token', $token)
                        ->withNotification($notif)
                        ->withData($payload)
                        ->withAndroidConfig($android)
                        ->withApnsConfig($apns),
                    $batch
                );
                try {
                    $report = $this->messaging->sendAll($messages);
                } catch (AuthenticationError $e) {
                    $report = self::resetInstance(); self::$instance->messaging->sendAll($messages);
                }
                $sent   += $report->successes()->count();
                $failed += $report->failures()->count();
            } catch (\Throwable $e) {
                Log::error('[FCM] Broadcast batch failed: ' . $e->getMessage());
                $failed += count($batch);
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    public function sendDeliveryReminder(string $fcmToken, string $orderCode): void
    {
        $this->send($fcmToken, [
            'title' => "Đơn #{$orderCode} — Nhắc nhở",
            'body'  => 'Bạn đã giao hàng xong chưa? Nhớ bấm Hoàn thành nhé!',
            'data'  => [
                'type'       => 'delivery_reminder',
                'order_code' => $orderCode,
            ],
            'ttl' => 300,
        ]);
    }

    /**
     * Thông báo chung cho tài xế (cảnh báo bỏ lỡ đơn, tự tắt online...).
     */
    public function sendDriverNotice(string $fcmToken, string $title, string $body, array $data = []): void
    {
        $this->send($fcmToken, [
            'title' => $title,
            'body'  => $body,
            'data'  => $data,
        ]);
    }

    public function sendOverdueDebt(string $fcmToken, string $amount): void
    {
        $this->send($fcmToken, [
            'title' => '⚠️ Công nợ quá hạn',
            'body'  => "Bạn có công nợ {$amount}₫ đã quá hạn. Vui lòng thanh toán để tiếp tục nhận đơn.",
            'data'  => ['type' => 'debt_overdue'],
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

            try {
                $this->messaging->send($message);
            } catch (AuthenticationError $e) {
                self::resetInstance(); self::$instance->messaging->send($message);
            }
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
