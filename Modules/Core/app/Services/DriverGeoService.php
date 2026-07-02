<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DriverGeoService
{
    // geo:drivers:{city_id}
    private static function key(int $cityId): string
    {
        return "geo:drivers:{$cityId}";
    }

    public static function add(int $driverId, int $cityId, float $lat, float $lng): void
    {
        try {
            Redis::geoadd(self::key($cityId), $lng, $lat, $driverId);
        } catch (\Throwable $e) {
            Log::warning("[GeoService] geoadd failed: " . $e->getMessage());
        }
    }

    public static function remove(int $driverId, int $cityId): void
    {
        try {
            Redis::zrem(self::key($cityId), $driverId);
        } catch (\Throwable $e) {
            Log::warning("[GeoService] zrem failed: " . $e->getMessage());
        }
    }

    /**
     * @return array<int, float>  [driverId => distanceKm]
     */
    public static function nearby(int $cityId, float $lat, float $lng, float $radiusKm): array
    {
        try {
            $results = Redis::georadius(
                self::key($cityId),
                $lng, $lat,
                $radiusKm, 'km',
                'WITHDIST', 'ASC', 'COUNT', 100
            );

            $map = [];
            foreach ($results as $row) {
                $map[(int) $row[0]] = (float) $row[1];
            }
            return $map;
        } catch (\Throwable $e) {
            Log::warning("[GeoService] georadius failed: " . $e->getMessage());
            return [];
        }
    }
}
