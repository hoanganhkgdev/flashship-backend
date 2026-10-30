<?php
namespace Modules\Core\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Http\HttpClientOptions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Jobs\RtdbWriteJob;
use Symfony\Component\Cache\Adapter\Psr16Adapter;

class RTDBService
{
    private static ?\Kreait\Firebase\Contract\Database $db = null;

    /** Khoảng chờ trước mỗi lần thử ghi (lần đầu không chờ). */
    private const WRITE_DELAYS_MS = [0, 300, 1000, 2500];

    public static function db(): \Kreait\Firebase\Contract\Database
    {
        if (!self::$db) {
            self::$db = self::factory()
                ->withDatabaseUri(config('services.firebase.database_url'))
                ->createDatabase();
        }
        return self::$db;
    }

    /**
     * Factory dùng chung cho RTDB/FCM/Auth. Mặc định kreait chỉ cache OAuth
     * token trong bộ nhớ của 1 request PHP → request nào gọi Firebase cũng
     * phải xin token mới từ oauth2.googleapis.com (~200ms + 1 lần DNS, dễ
     * dính timeout). Lưu token vào cache store chung (Redis) để dùng lại
     * đến khi hết hạn (~1 giờ).
     */
    public static function factory(): Factory
    {
        return (new Factory)
            ->withHttpClientOptions(self::httpOptions())
            ->withServiceAccount(storage_path('app/firebase-service-account.json'))
            ->withAuthTokenCache(new Psr16Adapter(Cache::store(), 'firebase'));
    }

    /**
     * Guzzle mặc định không có timeout: khi mạng tới Google chập chờn, request
     * treo vô thời hạn và chiếm hết worker php-fpm, làm cả web không vào được.
     * VPS không có route IPv6 nhưng DNS vẫn trả AAAA của Google → ép IPv4 để
     * không chờ hết connect timeout trên IPv6 rồi mới thử lại.
     */
    public static function httpOptions(): HttpClientOptions
    {
        return HttpClientOptions::default()
            ->withGuzzleConfigOptions(['force_ip_resolve' => 'v4'])
            ->withConnectTimeout(3)
            ->withReadTimeout(8)
            ->withTimeout(10);
    }

    /**
     * Đưa việc gọi Google ra khỏi request: tài xế/khách không phải chờ Firebase,
     * mạng tới Google chập chờn thì worker tự thử lại thay vì mất lệnh. Bật bằng
     * FIREBASE_OFFLOAD_CONNECTION=redis khi đã có worker cho queue `rtdb`/`fcm`;
     * mặc định `sync` = chạy ngay tại chỗ như trước. Không đẩy được vào queue
     * (Redis chết) thì cũng chạy tại chỗ, không bỏ lệnh.
     */
    public static function offload(ShouldQueue $job, string $queue, \Closure $inline): void
    {
        $connection = config('services.firebase.offload_connection', 'sync');
        if ($connection === 'sync') {
            $inline();
            return;
        }

        try {
            Queue::connection($connection)->pushOn($queue, $job);
        } catch (\Throwable $e) {
            Log::warning("[Firebase] Không đẩy được vào queue {$queue}, chạy trực tiếp: ".$e->getMessage());
            $inline();
        }
    }

    private static function write(string $op, string $path, mixed $value = null): void
    {
        // Chạy tại chỗ (fallback) chỉ thử 1 lần để không giữ worker php-fpm lâu.
        self::offload(
            new RtdbWriteJob($op, $path, $value),
            'rtdb',
            fn () => self::performWrite($op, $path, $value, [0]),
        );
    }

    /**
     * Ghi RTDB, lỗi thì tạo lại client và thử lại theo $delaysMs.
     *
     * @param  'set'|'update'|'remove'  $op
     */
    public static function performWrite(string $op, string $path, mixed $value = null, array $delaysMs = self::WRITE_DELAYS_MS): bool
    {
        $last = null;
        foreach ($delaysMs as $i => $delayMs) {
            if ($i > 0) {
                usleep($delayMs * 1000);
                self::$db = null;
            }
            try {
                $ref = self::db()->getReference($path);
                match ($op) {
                    'set'    => $ref->set($value),
                    'update' => $ref->update($value),
                    'remove' => $ref->remove(),
                };

                return true;
            } catch (\Throwable $e) {
                $last = $e;
            }
        }

        Log::error("[RTDB] {$op} {$path} failed after ".count($delaysMs).' attempts: '.$last?->getMessage());

        return false;
    }

    public static function pingCustomerNotification(int $userId): void
    {
        self::write('set', "customer_notifications/{$userId}", [
            'updated_at' => now()->timestamp,
        ]);
    }

    public static function updateOrderStatus(string $orderCode, string $status): void
    {
        self::write('update', "orders/{$orderCode}", [
            'status'     => $status,
            'updated_at' => now()->timestamp,
        ]);
    }

    public static function clearOrder(string $orderCode): void
    {
        self::write('remove', "orders/{$orderCode}");
    }

    /** @return array<string, array>|null */
    public static function getOrderStates(): ?array
    {
        try {
            return self::db()->getReference('orders')->getValue() ?? [];
        } catch (\Throwable $e) {
            Log::error('[RTDB] getOrderStates failed: ' . $e->getMessage());

            return null;
        }
    }

    /** Xóa nhiều node orders trong một request multi-location. */
    public static function clearOrders(array $orderCodes): bool
    {
        if ($orderCodes === []) {
            return true;
        }

        $updates = array_fill_keys(array_map('strval', $orderCodes), null);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                self::db()->getReference('orders')->update($updates);

                return true;
            } catch (\Throwable $e) {
                if ($attempt === 3) {
                    Log::error('[RTDB] clearOrders failed after 3 attempts: ' . $e->getMessage(), [
                        'count' => count($orderCodes),
                    ]);

                    return false;
                }
                usleep($attempt * 100_000);
                self::$db = null;
            }
        }

        return false;
    }

    /** Báo revision cho các bản app cũ còn nghe nút thành phố; dùng update để không xoá nhánh `orders`. */
    public static function pingOrderMarket(int $cityId): void
    {
        self::write('update', "order_market/city_{$cityId}", [
            'revision' => now()->getTimestampMs(),
            'updated_at' => now()->timestamp,
        ]);
    }

    /**
     * Đăng (hoặc gỡ nếu $payload = null) một đơn trên danh sách đơn mới của thành phố.
     * App tài xế nghe trực tiếp nhánh này nên đơn hiện/biến mất ngay, không cần polling.
     */
    public static function setMarketOrder(int $cityId, int $orderId, ?array $payload): void
    {
        $path = "order_market/city_{$cityId}/orders/{$orderId}";
        $payload === null ? self::write('remove', $path) : self::write('set', $path, $payload);
    }

    /** Ghi đè toàn bộ nhánh `orders` của một thành phố (đồng bộ lại từ MySQL). */
    public static function replaceMarketOrders(int $cityId, array $payloadsById): bool
    {
        try {
            $ref = self::db()->getReference("order_market/city_{$cityId}/orders");
            $payloadsById === [] ? $ref->remove() : $ref->set($payloadsById);

            return true;
        } catch (\Throwable $e) {
            Log::error('[RTDB] replaceMarketOrders failed: '.$e->getMessage(), ['city_id' => $cityId]);

            return false;
        }
    }

    /**
     * Khóa/mở khóa tài khoản tài xế real-time — app sẽ detect và force logout.
     * Path: drivers/{driverId}/account_locked
     */
    public static function setAccountLocked(int $driverId, bool $locked): void
    {
        $path = "dispatch/driver_{$driverId}/account_locked";
        $locked ? self::write('set', $path, true) : self::write('remove', $path);
    }

    /**
     * Ghi device_id hiện tại của tài xế — thiết bị khác sẽ detect và force logout.
     * Path: dispatch/driver_{driverId}/session_device
     *
     * Ghi ngay (không qua queue): Security Rules cần giá trị này đúng lúc app
     * vừa đăng nhập xong ký Firebase bằng custom token. Ghi hụt thì lần xin
     * firebase-token sau sẽ thấy lệch thiết bị và đăng xuất oan → thử vài lần.
     */
    public static function writeSessionDevice(int $driverId, string $deviceId): void
    {
        self::performWrite('set', "dispatch/driver_{$driverId}/session_device", $deviceId, [0, 200, 600]);
    }

    /**
     * Đọc thiết bị đang sở hữu phiên để backend không cấp token Firebase cho máy cũ.
     * Ném lỗi khi không đọc được Firebase — để nơi gọi phân biệt "mất mạng"
     * với "chưa có phiên" (null), không đăng xuất tài xế chỉ vì lỗi mạng.
     */
    public static function getSessionDevice(int $driverId): ?string
    {
        $value = self::db()->getReference("dispatch/driver_{$driverId}/session_device")->getValue();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Cập nhật trạng thái online/offline của tài xế trên RTDB.
     *
     * CHỈ ghi is_online — không đụng updated_at. App tài xế là nguồn DUY
     * NHẤT ghi updated_at (kèm lat/lng thật mỗi lần). Nếu hàm này cũng bump
     * updated_at ở đây (không có toạ độ mới đi kèm) sẽ tạo đúng kiểu lỗi đã
     * gây ra vụ đơn #12117: timestamp trông "vừa mới" trong khi toạ độ thực
     * ra vẫn cũ — hệ thống phát đơn tưởng nhầm vị trí đang fresh.
     */
    public static function setDriverOnlineStatus(int $driverId, bool $isOnline): void
    {
        self::write('update', "locations/driver_{$driverId}", [
            'is_online' => $isOnline,
        ]);
    }

    /**
     * Xoá hẳn node vị trí — dùng khi chắc chắn phiên đó đã kết thúc hẳn (vd
     * đăng nhập thiết bị mới thay thế thiết bị cũ) và sẽ không còn ai dọn
     * toạ độ cũ nữa, thay vì chỉ tắt cờ is_online mà để toạ độ cũ nằm lại.
     */
    public static function removeDriverLocation(int $driverId): void
    {
        self::write('remove', "locations/driver_{$driverId}");
    }

    /**
     * Đọc toàn bộ node `locations` (toạ độ realtime mọi tài xế) trong 1 lần
     * gọi — dùng bởi các nơi cần quét toạ độ tài xế tại thời điểm xử lý
     * (dispatch, tìm tài xế gần) thay vì đọc qua bản sao MySQL đã lỗi thời.
     */
    public static function getDriverLocations(): ?array
    {
        try {
            return self::db()->getReference('locations')->getValue() ?? [];
        } catch (\Throwable $e) {
            Log::error('[RTDB] getDriverLocations failed: ' . $e->getMessage());
            // null = không đọc được Firebase; [] = đọc thành công và thật sự
            // không có node nào. Cron GPS cần phân biệt để không đóng phiên
            // của toàn bộ tài xế chỉ vì một phút lỗi mạng.
            return null;
        }
    }

    /**
     * Cấp Firebase custom token gắn với 1 UID theo thiết bị (không phải theo
     * user) — dùng để Security Rules so khớp `auth.uid` với `session_device`
     * hiện tại, chặn cứng thiết bị cũ ghi RTDB sau khi bị đăng nhập đè (kể cả
     * khi đang chạy nền, không nhận được tín hiệu logout kịp thời qua
     * listener thường — xem điều tra tài xế #405/#414).
     */
    public static function createCustomAuthToken(string $uid): ?string
    {
        try {
            return self::factory()->createAuth()->createCustomToken($uid)->toString();
        } catch (\Throwable $e) {
            Log::error('[RTDB] createCustomAuthToken failed: ' . $e->getMessage());
            return null;
        }
    }
}
