<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DriverGeoService
{
    const GPS_TTL_SECS = 600; // 10 phút — loại driver mất GPS sớm hơn

    private static function geoKey(int $cityId): string
    {
        return "drivers:geo:{$cityId}";
    }

    private static function gpsKey(int $driverId): string
    {
        return "driver:gps:{$driverId}";
    }

    /**
     * Đăng ký tài xế vào Redis GEO ngay khi bật online bằng tọa độ DB cuối cùng.
     * Đảm bảo dispatch tìm thấy driver ngay, không cần chờ GPS update đầu tiên.
     */
    public static function registerOnline(int $driverId, int $cityId, float $lat, float $lng): void
    {
        try {
            Redis::geoadd(self::geoKey($cityId), $lng, $lat, $driverId);
            // TTL dài hơn updateLocation vì đây là vị trí "cuối biết" — chờ GPS thực thay thế
            Redis::setex(self::gpsKey($driverId), self::GPS_TTL_SECS, 1);
        } catch (\Throwable $e) {
            Log::error("[DriverGeo] registerOnline #{$driverId} failed: " . $e->getMessage());
        }
    }

    /**
     * Ghi vị trí tài xế vào Redis GEO.
     * Gọi mỗi khi app driver gửi GPS update.
     */
    public static function updateLocation(int $driverId, int $cityId, float $lat, float $lng): void
    {
        try {
            Redis::geoadd(self::geoKey($cityId), $lng, $lat, $driverId);
            Redis::setex(self::gpsKey($driverId), self::GPS_TTL_SECS, 1);
        } catch (\Throwable $e) {
            Log::error("[DriverGeo] updateLocation #{$driverId} failed: " . $e->getMessage());
        }
    }

    /**
     * Xóa tài xế khỏi Redis GEO khi offline.
     */
    public static function removeDriver(int $driverId, int $cityId): void
    {
        try {
            Redis::zrem(self::geoKey($cityId), $driverId);
            Redis::del(self::gpsKey($driverId));
        } catch (\Throwable $e) {
            Log::error("[DriverGeo] removeDriver #{$driverId} failed: " . $e->getMessage());
        }
    }

    /**
     * Trả về danh sách driver ID trong bán kính, kèm khoảng cách thực (km).
     * Chỉ bao gồm driver có GPS còn fresh (TTL chưa hết).
     *
     * @return array<int, float>  [driverId => distanceKm]
     */
    public static function getNearby(int $cityId, float $lat, float $lng, float $radiusKm): array
    {
        try {
            $results = Redis::command('GEORADIUS', [
                self::geoKey($cityId),
                $lng, $lat,
                $radiusKm, 'km',
                'WITHDIST', 'ASC', 'COUNT', 100,
            ]);

            if (empty($results)) return [];

            $drivers = [];
            $gpsKeys = array_map(fn($item) => self::gpsKey((int) $item[0]), $results);

            // Pipeline check TTL một lần thay vì N lần round-trip
            $pipeline = Redis::pipeline();
            foreach ($gpsKeys as $key) {
                $pipeline->exists($key);
            }
            $exists = $pipeline->execute();

            foreach ($results as $i => $item) {
                if ($exists[$i]) {
                    $drivers[(int) $item[0]] = (float) $item[1];
                }
            }

            return $drivers;
        } catch (\Throwable $e) {
            Log::error("[DriverGeo] getNearby city={$cityId} failed: " . $e->getMessage());
            return [];
        }
    }
}
