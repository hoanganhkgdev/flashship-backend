<?php

namespace Modules\Shop\Services;

use Modules\Core\Services\GoogleMapService;
use Modules\Shop\Models\ShopPricingConfig;

class ShopPricingService
{
    // ── Hardcoded fallback (dùng khi chưa seed DB) ─────────────────────────────

    private static array $fallbackFood = [
        ['max_km' => 3.4,  'fee' => 10000],
        ['max_km' => 4.4,  'fee' => 13000],
        ['max_km' => 5.4,  'fee' => 15000],
        ['max_km' => 6.4,  'fee' => 18000],
        ['max_km' => 7.4,  'fee' => 20000],
        ['max_km' => 8.4,  'fee' => 23000],
        ['max_km' => 10.0, 'fee' => 25000],
    ];

    private static array $fallbackFlowers = [
        ['max_km' => 3.4,  'fee' => 15000],
        ['max_km' => 4.4,  'fee' => 18000],
        ['max_km' => 5.4,  'fee' => 20000],
        ['max_km' => 6.4,  'fee' => 23000],
        ['max_km' => 7.4,  'fee' => 25000],
        ['max_km' => 8.4,  'fee' => 28000],
        ['max_km' => 10.0, 'fee' => 30000],
    ];

    // ── Public API ─────────────────────────────────────────────────────────────

    public static function estimate(
        string $cargoType,
        float $km,
        ?float $cargoWeightKg = null,
        ?int $cityId = null
    ): array {
        return self::calculate($cargoType, $km, $cargoWeightKg, $cityId);
    }

    public static function estimateFromCoords(
        string $cargoType,
        float $pickupLat, float $pickupLng,
        float $deliveryLat, float $deliveryLng,
        ?float $cargoWeightKg = null,
        ?int $cityId = null
    ): array {
        $km = GoogleMapService::roadDistanceKm($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);
        return self::calculate($cargoType, $km, $cargoWeightKg, $cityId);
    }

    public static function estimateFromAddresses(
        string $cargoType,
        string $pickupAddress,
        string $deliveryAddress,
        ?float $cargoWeightKg = null,
        ?int $cityId = null
    ): array {
        $pickup   = GoogleMapService::geocode($pickupAddress);
        $delivery = GoogleMapService::geocode($deliveryAddress);

        if ($pickup && $delivery) {
            return self::estimateFromCoords(
                $cargoType,
                $pickup['lat'],   $pickup['lng'],
                $delivery['lat'], $delivery['lng'],
                $cargoWeightKg,
                $cityId
            );
        }

        $result = self::calculate($cargoType, 3.0, $cargoWeightKg, $cityId);
        $result['geocode_failed'] = true;
        return $result;
    }

    // ── Internal ───────────────────────────────────────────────────────────────

    private static function calculate(
        string $cargoType,
        float $km,
        ?float $weightKg,
        ?int $cityId
    ): array {
        // Lấy config từ DB
        $cfg = ShopPricingConfig::forCargo($cargoType, $cityId);

        if ($cfg) {
            $slabs          = $cfg->slabs;
            $overMaxPerKm   = $cfg->over_max_per_km;
            $weightPerKg    = $cfg->weight_per_kg;
        } else {
            // Fallback hardcode
            $slabs        = $cargoType === 'flowers'
                ? self::$fallbackFlowers
                : self::$fallbackFood;
            $overMaxPerKm = 3000;
            $weightPerKg  = $cargoType === 'parcel' ? 1000 : 0;
        }

        $baseFee = self::slabFee($km, $slabs, $overMaxPerKm);

        $weightSurcharge = 0;
        if ($weightPerKg > 0 && $weightKg !== null && $weightKg > 0) {
            $weightSurcharge = (int) (ceil($weightKg) * $weightPerKg);
        }

        $nightSurcharge = self::nightSurcharge();

        return [
            'cargo_type'       => $cargoType,
            'distance_km'      => round($km, 2),
            'base_fee'         => $baseFee,
            'weight_surcharge' => $weightSurcharge,
            'night_surcharge'  => $nightSurcharge,
            'fee'              => $baseFee + $weightSurcharge + $nightSurcharge,
        ];
    }

    private static function slabFee(float $km, array $slabs, int $overMaxPerKm): int
    {
        foreach ($slabs as $slab) {
            if ($km <= (float) $slab['max_km']) {
                return (int) $slab['fee'];
            }
        }
        $last   = end($slabs);
        $overKm = $km - (float) $last['max_km'];
        return (int) $last['fee'] + (int) (ceil($overKm) * $overMaxPerKm);
    }

    private static function nightSurcharge(): int
    {
        $hour = (int) now()->format('G');
        if ($hour === 23 || $hour === 0) return 5_000;
        if ($hour >= 1 && $hour <= 3)   return 10_000;
        return 0;
    }
}
