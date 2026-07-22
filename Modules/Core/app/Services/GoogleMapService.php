<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleMapService
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
                    // routes[0] là tuyến Google đề xuất (nhanh nhất, thực tế nhất)
                    // — đây là tuyến tài xế thực sự đi theo GPS, không lấy alternatives
                    $legs   = $res->json('routes.0.legs') ?? [];
                    $meters = array_sum(array_map(fn ($leg) => $leg['distance']['value'] ?? 0, $legs));

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
     * Khoảng cách đường đi thật cho NHIỀU điểm xuất phát cùng lúc tới 1 điểm
     * đến, dùng Google Distance Matrix API (1 request xử lý cả lô, tối đa 25
     * điểm xuất phát/lần — đúng bài toán "N tài xế → 1 điểm lấy hàng", hiệu quả
     * hơn hẳn gọi Directions API riêng lẻ từng người).
     *
     * @param  array<int, array{lat: float, lng: float}>  $origins  [driverId => ['lat'=>, 'lng'=>]]
     * @return array<int, float>  [driverId => distanceKm] — luôn trả đủ key cho mọi $origins
     *                            (fallback haversine×2 nếu API lỗi/phần tử đó lỗi riêng lẻ).
     */
    public static function roadDistanceBatchKm(array $origins, float $destLat, float $destLng): array
    {
        if (empty($origins)) {
            return [];
        }

        $cacheKeyFor = fn (float $lat, float $lng) => sprintf('road_dist_%s_%s_%s_%s',
            round($lat, 5), round($lng, 5), round($destLat, 5), round($destLng, 5)
        );

        $result   = [];
        $uncached = [];
        foreach ($origins as $id => $coord) {
            $cached = Cache::get($cacheKeyFor($coord['lat'], $coord['lng']));
            if ($cached !== null) {
                $result[$id] = (float) $cached;
            } else {
                $uncached[$id] = $coord;
            }
        }

        if (empty($uncached)) {
            return $result;
        }

        // Distance Matrix giới hạn 25 điểm xuất phát/request — quy mô hiện tại
        // (vài chục tài xế/thành phố) chia tối đa 1-2 lô là đủ.
        foreach (array_chunk($uncached, 25, true) as $chunk) {
            $ids = array_keys($chunk);
            try {
                $res = Http::timeout(8)->get(
                    'https://maps.googleapis.com/maps/api/distancematrix/json',
                    [
                        'origins'      => collect($chunk)->map(fn ($c) => "{$c['lat']},{$c['lng']}")->implode('|'),
                        'destinations' => "{$destLat},{$destLng}",
                        'mode'         => 'driving',
                        'key'          => config('services.google_maps.api_key'),
                    ]
                );

                if ($res->successful() && $res->json('status') === 'OK') {
                    $rows = $res->json('rows') ?? [];
                    foreach ($rows as $i => $row) {
                        $id = $ids[$i] ?? null;
                        if ($id === null) continue;
                        $element = $row['elements'][0] ?? null;
                        $coord   = $chunk[$id];
                        if (($element['status'] ?? '') === 'OK') {
                            $km = (float) $element['distance']['value'] / 1000;
                            $result[$id] = $km;
                            Cache::put($cacheKeyFor($coord['lat'], $coord['lng']), $km, 1800);
                        } else {
                            $result[$id] = self::haversineKm($coord['lat'], $coord['lng'], $destLat, $destLng) * 2.0;
                        }
                    }
                    continue;
                }

                Log::warning('Google Distance Matrix failed', ['status' => $res->json('status')]);
            } catch (\Throwable $e) {
                Log::warning('Google Distance Matrix exception', ['error' => $e->getMessage()]);
            }

            // Cả lô lỗi (request thất bại/exception) — fallback haversine×2 cho từng điểm còn thiếu.
            foreach ($chunk as $id => $coord) {
                if (!isset($result[$id])) {
                    $result[$id] = self::haversineKm($coord['lat'], $coord['lng'], $destLat, $destLng) * 2.0;
                }
            }
        }

        return $result;
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
