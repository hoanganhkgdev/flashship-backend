<?php
namespace Modules\Pricing\Services;

use Modules\Pricing\Models\PricingConfig;

class PricingService
{
    private const RATES = [
        'delivery' => ['base' => 15_000, 'per_km' => 5_000,  'min' => 15_000],
        'shopping' => ['base' => 20_000, 'per_km' => 6_000,  'min' => 20_000],
        'topup'    => ['base' => 10_000, 'per_km' => 0,      'min' => 10_000],
        'bike'     => ['base' => 15_000, 'per_km' => 5_000,  'min' => 15_000],
        'motor'    => ['base' => 15_000, 'per_km' => 6_000,  'min' => 15_000],
        'car'      => ['base' => 30_000, 'per_km' => 15_000, 'min' => 30_000],
    ];

    private static function getRates(string $serviceType): array
    {
        $config = PricingConfig::forService($serviceType);
        if ($config) {
            return ['base' => $config->base_fee, 'per_km' => $config->per_km_fee, 'min' => $config->min_fee];
        }
        return self::RATES[$serviceType] ?? self::RATES['bike'];
    }

    public static function estimate(string $serviceType, float $distanceKm): array
    {
        $rates = self::getRates($serviceType);
        $raw   = $rates['base'] + $distanceKm * $rates['per_km'];
        $fee   = (int) (ceil(max($raw, $rates['min']) / 1000) * 1000);

        return [
            'service_type' => $serviceType,
            'distance_km'  => round($distanceKm, 2),
            'fee'          => $fee,
        ];
    }

    public static function estimateFromCoords(
        string $serviceType,
        float $pickupLat, float $pickupLng,
        float $deliveryLat, float $deliveryLng
    ): array {
        $distance = self::haversine($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);
        return self::estimate($serviceType, $distance);
    }

    public static function estimateFromAddresses(
        string $serviceType,
        string $pickupAddress,
        string $deliveryAddress,
        ?string $cityName = null
    ): array {
        // Fallback to 3km estimate when no geocoding available
        $result = self::estimate($serviceType, 3.0);
        $result['geocode_failed'] = true;
        return $result;
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function serviceTypes(): array
    {
        return array_keys(self::RATES);
    }
}
