<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DriverGeoService
{
    // GPS mới hơn 10 phút → dùng từ Firebase trực tiếp; cũ hơn → fallback DB
    const GPS_FRESH_SECS = 600;

    // TTL cho flag key driver:gps:{id} — chỉ là ping marker, không dùng để filter dispatch
    const GPS_TTL_SECS = 600;

    private static function geoKey(int $cityId): string
    {
        return "drivers:geo:{$cityId}";
    }

    // Key format cũ — dọn dẹp khi gặp
    private static function geoKeyLegacy(int $cityId): string
    {
        return "drivers:geo:city:{$cityId}";
    }

    private static function gpsKey(int $driverId): string
    {
        return "driver:gps:{$driverId}";
    }

    /**
     * Đăng ký tài xế vào Redis GEO khi bật online.
     * Dùng tọa độ DB cuối biết — dispatch tìm thấy ngay, không cần chờ GPS ping đầu tiên.
     */
    public static function registerOnline(int $driverId, int $cityId, float $lat, float $lng): void
    {
        try {
            Redis::geoadd(self::geoKey($cityId), $lng, $lat, $driverId);
            Redis::zrem(self::geoKeyLegacy($cityId), $driverId);
            Redis::setex(self::gpsKey($driverId), self::GPS_TTL_SECS, 1);
        } catch (\Throwable $e) {
            Log::error("[DriverGeo] registerOnline #{$driverId} failed: " . $e->getMessage());
        }
    }

    /**
     * Cập nhật vị trí tài xế vào Redis GEO.
     * Gọi mỗi khi app driver gửi GPS update, hoặc từ SyncDriverGeoCommand mỗi 30s.
     */
    public static function updateLocation(int $driverId, int $cityId, float $lat, float $lng): void
    {
        try {
            Redis::geoadd(self::geoKey($cityId), $lng, $lat, $driverId);
            Redis::zrem(self::geoKeyLegacy($cityId), $driverId);
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
            Redis::zrem(self::geoKeyLegacy($cityId), $driverId);
            Redis::del(self::gpsKey($driverId));
        } catch (\Throwable $e) {
            Log::error("[DriverGeo] removeDriver #{$driverId} failed: " . $e->getMessage());
        }
    }

    /**
     * Xóa khỏi GEO những driver ID không có trong danh sách online.
     * Gọi từ SyncDriverGeoCommand mỗi 30s để dọn stale entries.
     */
    public static function removeOfflineDrivers(int $cityId, array $onlineIds): void
    {
        try {
            $inGeo = Redis::zrange(self::geoKey($cityId), 0, -1);
            foreach ($inGeo as $idStr) {
                $id = (int) $idStr;
                if (!in_array($id, $onlineIds, true)) {
                    Redis::zrem(self::geoKey($cityId), $idStr);
                }
            }

            $inGeoLegacy = Redis::zrange(self::geoKeyLegacy($cityId), 0, -1);
            foreach ($inGeoLegacy as $idStr) {
                $id = (int) $idStr;
                if (!in_array($id, $onlineIds, true)) {
                    Redis::zrem(self::geoKeyLegacy($cityId), $idStr);
                }
            }
        } catch (\Throwable $e) {
            Log::error("[DriverGeo] removeOfflineDrivers city={$cityId} failed: " . $e->getMessage());
        }
    }

    /**
     * Trả về danh sách driver ID trong bán kính, kèm khoảng cách (km).
     * Không filter theo GPS TTL — tài xế online luôn được phát đơn kể cả khi GPS hơi cũ.
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
            foreach ($results as $item) {
                $drivers[(int) $item[0]] = (float) $item[1];
            }

            return $drivers;
        } catch (\Throwable $e) {
            Log::error("[DriverGeo] getNearby city={$cityId} failed: " . $e->getMessage());
            return [];
        }
    }
}
