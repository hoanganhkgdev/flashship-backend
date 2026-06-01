<?php
namespace Modules\Core\Services;

use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;

class RTDBService
{
    private static ?\Kreait\Firebase\Contract\Database $db = null;

    private static function db(): \Kreait\Firebase\Contract\Database
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
}
