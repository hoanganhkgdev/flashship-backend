<?php
namespace Modules\Core\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Http\HttpClientOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Cache\Adapter\Psr16Adapter;

class RTDBService
{
    private static ?\Kreait\Firebase\Contract\Database $db = null;

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

    public static function pingCustomerNotification(int $userId): void
    {
        try {
            self::db()->getReference("customer_notifications/{$userId}")->set([
                'updated_at' => now()->timestamp,
            ]);
        } catch (\Throwable $e) {
            Log::error('[RTDB] pingCustomerNotification failed: ' . $e->getMessage());
        }
    }

    public static function updateOrderStatus(string $orderCode, string $status): void
    {
        try {
            self::db()->getReference("orders/{$orderCode}")->update([
                'status'     => $status,
                'updated_at' => now()->timestamp,
            ]);
        } catch (\Throwable $e) {
            Log::error('[RTDB] updateOrderStatus failed: ' . $e->getMessage());
        }
    }

    public static function clearOrder(string $orderCode): bool
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                self::db()->getReference("orders/{$orderCode}")->remove();

                return true;
            } catch (\Throwable $e) {
                if ($attempt === 3) {
                    Log::error('[RTDB] clearOrder failed after 3 attempts: ' . $e->getMessage(), [
                        'order_code' => $orderCode,
                    ]);

                    return false;
                }
                usleep($attempt * 100_000);
                self::$db = null;
            }
        }

        return false;
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
        try {
            self::db()->getReference("order_market/city_{$cityId}")->update([
                'revision' => now()->getTimestampMs(),
                'updated_at' => now()->timestamp,
            ]);
        } catch (\Throwable $e) {
            Log::error('[RTDB] pingOrderMarket failed: '.$e->getMessage());
        }
    }

    /**
     * Đăng (hoặc gỡ nếu $payload = null) một đơn trên danh sách đơn mới của thành phố.
     * App tài xế nghe trực tiếp nhánh này nên đơn hiện/biến mất ngay, không cần polling.
     */
    public static function setMarketOrder(int $cityId, int $orderId, ?array $payload): void
    {
        try {
            $ref = self::db()->getReference("order_market/city_{$cityId}/orders/{$orderId}");
            $payload === null ? $ref->remove() : $ref->set($payload);
        } catch (\Throwable $e) {
            Log::error('[RTDB] setMarketOrder failed: '.$e->getMessage(), ['city_id' => $cityId, 'order_id' => $orderId]);
        }
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
        try {
            $ref = self::db()->getReference("dispatch/driver_{$driverId}/account_locked");
            $locked ? $ref->set(true) : $ref->remove();
        } catch (\Throwable $e) {
            Log::error('[RTDB] setAccountLocked failed: ' . $e->getMessage());
        }
    }

    /**
     * Ghi device_id hiện tại của tài xế — thiết bị khác sẽ detect và force logout.
     * Path: dispatch/driver_{driverId}/session_device
     */
    public static function writeSessionDevice(int $driverId, string $deviceId): void
    {
        try {
            self::db()->getReference("dispatch/driver_{$driverId}/session_device")->set($deviceId);
        } catch (\Throwable $e) {
            Log::error('[RTDB] writeSessionDevice failed: ' . $e->getMessage());
        }
    }

    /** Đọc thiết bị đang sở hữu phiên để backend không cấp token Firebase cho máy cũ. */
    public static function getSessionDevice(int $driverId): ?string
    {
        try {
            $value = self::db()->getReference("dispatch/driver_{$driverId}/session_device")->getValue();
            return is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable $e) {
            Log::error('[RTDB] getSessionDevice failed: ' . $e->getMessage());
            return null;
        }
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
        try {
            self::db()->getReference("locations/driver_{$driverId}")->update([
                'is_online' => $isOnline,
            ]);
        } catch (\Throwable $e) {
            Log::error('[RTDB] setDriverOnlineStatus failed: ' . $e->getMessage());
        }
    }

    /**
     * Xoá hẳn node vị trí — dùng khi chắc chắn phiên đó đã kết thúc hẳn (vd
     * đăng nhập thiết bị mới thay thế thiết bị cũ) và sẽ không còn ai dọn
     * toạ độ cũ nữa, thay vì chỉ tắt cờ is_online mà để toạ độ cũ nằm lại.
     */
    public static function removeDriverLocation(int $driverId): void
    {
        try {
            self::db()->getReference("locations/driver_{$driverId}")->remove();
        } catch (\Throwable $e) {
            Log::error('[RTDB] removeDriverLocation failed: ' . $e->getMessage());
        }
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
