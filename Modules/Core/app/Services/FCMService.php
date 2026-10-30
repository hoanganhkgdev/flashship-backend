<?php
namespace Modules\Core\Services;

use Illuminate\Support\Facades\Log;
use Modules\Core\Jobs\FcmSendJob;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\Notification;

class FCMService
{
    private static ?self $instance = null;
    private $messaging;

    private function __construct()
    {
        $this->messaging = RTDBService::factory()->createMessaging();
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
     * Lỗi FCM hay gặp không phải do sai key (đã xác nhận key hợp lệ) mà do
     * mạng VPS chập chờn thoáng qua tới Google (cùng loại lỗi cURL cũng thấy
     * ở đọc RTDB) — bước lấy OAuth2 token thất bại ngắt quãng khiến request
     * gửi đi thiếu hẳn header xác thực (401 "missing credential"). Thử lại
     * tối đa 3 lần, mỗi lần cách nhau dài hơn, tạo lại instance (token mới)
     * ở các lần thử sau — đủ để vượt qua hầu hết đợt chập chờn ngắn.
     */
    private function sendWithRetry(\Closure $fn): mixed
    {
        $delaysMs = [0, 300, 800];
        $last = null;
        foreach ($delaysMs as $i => $delayMs) {
            if ($i > 0) {
                usleep($delayMs * 1000);
                self::resetInstance();
            }
            try {
                return $fn(self::$instance);
            } catch (\Throwable $e) {
                $last = $e;
            }
        }
        throw $last;
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
            'processing' => 'Tài xế đã lấy hàng',
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
                $report = $this->sendWithRetry(fn (self $fcm) => $fcm->messaging->sendAll($messages));
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
        RTDBService::offload(new FcmSendJob($token, $payload), 'fcm', fn () => $this->deliver($token, $payload));
    }

    public function deliver(string $token, array $payload): void
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

            $this->sendWithRetry(fn (self $fcm) => $fcm->messaging->send($message));
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
