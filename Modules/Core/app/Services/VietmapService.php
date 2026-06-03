<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VietmapService
{
    /**
     * Road distance in km using Google Directions API (driving mode).
     * Cache 30 minutes. Fallback: haversine × 2.0 on API error.
     */
    public static function roadDistanceKm(
        float $fromLat, float $fromLng,
        float $toLat,   float $toLng
    ): float {
        $cacheKey = sprintf('road_dist_%s_%s_%s_%s',
            round($fromLat, 5), round($fromLng, 5),
            round($toLat, 5),   round($toLng, 5)
        );

        return Cache::remember($cacheKey, 1800, function () use ($fromLat, $fromLng, $toLat, $toLng) {
            try {
                $res = Http::timeout(5)->get(
                    config('services.google_maps.directions_url'),
                    [
                        'origin'      => "{$fromLat},{$fromLng}",
                        'destination' => "{$toLat},{$toLng}",
                        'mode'        => 'driving',
                        'key'         => config('services.google_maps.api_key'),
                    ]
                );

                if ($res->successful() && $res->json('status') === 'OK') {
                    $meters = $res->json('routes.0.legs.0.distance.value');
                    if ($meters > 0) {
                        return (float) $meters / 1000;
                    }
                }

                Log::warning('Google Directions API failed', [
                    'status' => $res->json('status'),
                    'error'  => $res->json('error_message'),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Google Directions exception', ['error' => $e->getMessage()]);
            }

            return self::haversineKm($fromLat, $fromLng, $toLat, $toLng) * 2.0;
        });
    }

    /**
     * Geocode một địa chỉ → trả về ['lat', 'lng'] hoặc null nếu thất bại.
     * Cache 24 giờ vì địa chỉ thường không đổi.
     */
    public static function geocode(string $address): ?array
    {
        $cacheKey = 'geocode_' . md5($address);

        return Cache::remember($cacheKey, 86400, function () use ($address) {
            try {
                $res = Http::timeout(5)->get(
                    'https://maps.googleapis.com/maps/api/geocode/json',
                    [
                        'address'    => $address,
                        'key'        => config('services.google_maps.api_key'),
                        'language'   => 'vi',
                        'components' => 'country:VN',
                    ]
                );

                if ($res->successful() && $res->json('status') === 'OK') {
                    $loc = $res->json('results.0.geometry.location');
                    if ($loc) {
                        return ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng']];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Geocode exception', ['address' => $address, 'error' => $e->getMessage()]);
            }
            return null;
        });
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
