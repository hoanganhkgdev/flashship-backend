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

    public static function updateOrderLocation(string $orderCode, float $lat, float $lng): void
    {
        try {
            self::db()->getReference("order_location/{$orderCode}")->set([
                'lat' => $lat,
                'lng' => $lng,
                'ts'  => now()->timestamp,
            ]);
        } catch (\Throwable $e) {
            Log::error('[RTDB] updateOrderLocation failed: ' . $e->getMessage());
        }
    }

    public static function clearOrderLocation(string $orderCode): void
    {
        try {
            self::db()->getReference("order_location/{$orderCode}")->remove();
        } catch (\Throwable $e) {
            Log::error('[RTDB] clearOrderLocation failed: ' . $e->getMessage());
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
            self::db()->getReference("order_location/{$orderCode}")->remove();
        } catch (\Throwable $e) {
            Log::error('[RTDB] clearOrder failed: ' . $e->getMessage());
        }
    }

    public static function publishPendingOrder(\Modules\Order\Models\Order $order): void
    {
        try {
            self::db()->getReference("pending_orders/{$order->city_id}/{$order->code}")->set([
                'id'               => $order->id,
                'code'             => $order->code,
                'service_type'     => $order->service_type,
                'pickup_address'   => $order->pickup_address,
                'pickup_lat'       => $order->pickup_lat,
                'pickup_lng'       => $order->pickup_lng,
                'delivery_address' => $order->delivery_address,
                'shipping_fee'     => $order->shipping_fee,
                'distance'         => $order->distance,
                'expires_at'       => now()->addSeconds(300)->timestamp,
            ]);
        } catch (\Throwable $e) {
            Log::error('[RTDB] publishPendingOrder failed: ' . $e->getMessage());
        }
    }

    public static function removePendingOrder(string $orderCode, int $cityId): void
    {
        try {
            self::db()->getReference("pending_orders/{$cityId}/{$orderCode}")->remove();
        } catch (\Throwable $e) {
            Log::error('[RTDB] removePendingOrder failed: ' . $e->getMessage());
        }
    }
}
