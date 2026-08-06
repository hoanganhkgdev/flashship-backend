<?php
namespace Modules\Core\Services;

use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;

class RTDBService
{
    private static ?\Kreait\Firebase\Contract\Database $db = null;

    public static function db(): \Kreait\Firebase\Contract\Database
    {
        if (!self::$db) {
            self::$db = (new Factory)
                ->withServiceAccount(storage_path('app/firebase-service-account.json'))
                ->withDatabaseUri(config('services.firebase.database_url'))
                ->createDatabase();
        }
        return self::$db;
    }

    /**
     * Ghi kèm điểm số thật (không chỉ ping) — app cập nhật ngay con số hiển
     * thị từ chính sự kiện realtime này, không cần đợi gọi API mới thấy số
     * mới (trước đây chỉ ping "có gì đó đổi", app phải gọi lại API mới biết
     * giá trị, tốn thêm 1 vòng round-trip cho phần quan trọng nhất — con số).
     */
    public static function pingDriverScore(int $driverId, int $score): void
    {
        try {
            self::db()->getReference("driver_score/{$driverId}")->set([
                'score'      => $score,
                'updated_at' => now()->timestamp,
            ]);
        } catch (\Throwable $e) {
            Log::error('[RTDB] pingDriverScore failed: ' . $e->getMessage());
        }
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

    public static function clearOrder(string $orderCode): void
    {
        try {
            self::db()->getReference("orders/{$orderCode}")->remove();
        } catch (\Throwable $e) {
            Log::error('[RTDB] clearOrder failed: ' . $e->getMessage());
        }
    }

    /**
     * Ghi offer đơn hàng vào RTDB để driver app nhận qua stream (thay FCM).
     * Path: dispatch/driver_{id}/offer
     */
    /**
     * Trả về true/false thay vì nuốt lỗi âm thầm — đây là kênh CHÍNH để tài
     * xế nhận offer, gọi cần biết chính xác có ghi được không để quyết định
     * có nên "cam kết" gán offer này cho tài xế hay chuyển ngay sang người kế.
     */
    public static function writeDriverOffer(int $driverId, array $data): bool
    {
        try {
            self::db()->getReference("dispatch/driver_{$driverId}/offer")->set($data);
            return true;
        } catch (\Throwable $e) {
            Log::error('[RTDB] writeDriverOffer failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cập nhật expires_at khi driver mở app — đồng hồ đếm ngược reset về APP_DECISION_SECS.
     */
    public static function updateDriverOfferExpiry(int $driverId, int $expiresAt): void
    {
        try {
            self::db()->getReference("dispatch/driver_{$driverId}/offer")->update([
                'expires_at' => $expiresAt,
            ]);
        } catch (\Throwable $e) {
            Log::error('[RTDB] updateDriverOfferExpiry failed: ' . $e->getMessage());
        }
    }

    /**
     * Xóa offer (timeout, accept, decline, customer cancel).
     */
    public static function clearDriverOffer(int $driverId): void
    {
        try {
            self::db()->getReference("dispatch/driver_{$driverId}/offer")->remove();
        } catch (\Throwable $e) {
            Log::error('[RTDB] clearDriverOffer failed: ' . $e->getMessage());
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
    public static function getDriverLocations(): array
    {
        try {
            return self::db()->getReference('locations')->getValue() ?? [];
        } catch (\Throwable $e) {
            Log::error('[RTDB] getDriverLocations failed: ' . $e->getMessage());
            return [];
        }
    }
}
