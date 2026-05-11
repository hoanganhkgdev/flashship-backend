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
}
