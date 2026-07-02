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

    public static function pingDriverScore(int $driverId): void
    {
        try {
            self::db()->getReference("driver_score/{$driverId}")->set([
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
    public static function writeDriverOffer(int $driverId, array $data): void
    {
        try {
            self::db()->getReference("dispatch/driver_{$driverId}/offer")->set($data);
        } catch (\Throwable $e) {
            Log::error('[RTDB] writeDriverOffer failed: ' . $e->getMessage());
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
     * Cập nhật vị trí GPS tài xế lên RTDB — admin map đọc real-time.
     */
    public static function updateDriverLocation(int $driverId, array $data): void
    {
        try {
            self::db()->getReference("flashship_main/locations/driver_{$driverId}")->update(array_filter([
                'lat'          => $data['lat'],
                'lng'          => $data['lng'],
                'bearing'      => $data['bearing'] ?? null,
                'is_online'    => true,
                'city_id'      => $data['city_id'] ?? null,
                'name'         => $data['name'] ?? null,
                'phone'        => $data['phone'] ?? null,
                'driver_score' => $data['driver_score'] ?? null,
                'updated_at'   => time() * 1000,
            ], fn($v) => $v !== null));
        } catch (\Throwable $e) {
            Log::error('[RTDB] updateDriverLocation failed: ' . $e->getMessage());
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
     */
    public static function setDriverOnlineStatus(int $driverId, bool $isOnline): void
    {
        try {
            self::db()->getReference("flashship_main/locations/driver_{$driverId}")->update([
                'is_online'  => $isOnline,
                'updated_at' => time() * 1000,
            ]);
        } catch (\Throwable $e) {
            Log::error('[RTDB] setDriverOnlineStatus failed: ' . $e->getMessage());
        }
    }
}
