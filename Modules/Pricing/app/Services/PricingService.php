<?php

namespace Modules\Pricing\Services;

use Modules\Core\Services\VietmapService;
use Modules\Pricing\Models\PricingConfig;

class PricingService
{
    private static function cfg(string $serviceType, ?int $cityId = null): ?array
    {
        return PricingConfig::forService($serviceType, $cityId)?->config_json;
    }

    // ── Delivery / Shopping — slab bậc thang ──────────────────────────────────
    private static function slabFee(float $km, string $serviceType, ?int $cityId = null): int
    {
        $cfg = self::cfg($serviceType, $cityId);

        if ($cfg && ($cfg['type'] ?? '') === 'slab') {
            foreach ($cfg['slabs'] as $slab) {
                if ($km <= $slab['max_km']) return (int) $slab['fee'];
            }
            $last = end($cfg['slabs']);
            return (int) $last['fee'] + (int) (ceil($km - $last['max_km']) * ($cfg['over_max_per_km'] ?? 3_000));
        }

        return match (true) {
            $km <= 3.5 => 15_000,
            $km <= 4.5 => 18_000,
            $km <= 5.5 => 20_000,
            $km <= 6.5 => 23_000,
            $km <= 7.5 => 25_000,
            $km <= 8.5 => 28_000,
            $km <= 10  => 30_000,
            default    => 30_000 + (int) (ceil($km - 10) * 3_000),
        };
    }

    // ── Xe Ôm — tuyến tính 2 bậc ──────────────────────────────────────────────
    private static function bikeFee(float $km, ?int $cityId = null): int
    {
        $cfg = self::cfg('bike', $cityId);

        if ($cfg && ($cfg['type'] ?? '') === 'tiered_linear') {
            $baseKm    = (float) $cfg['base_km'];
            $baseFee   = (int)   $cfg['base_fee'];
            $perKm     = (int)   $cfg['per_km_fee'];
            $higherKm  = (float) $cfg['higher_from_km'];
            $higherPer = (int)   $cfg['higher_per_km_fee'];

            if ($km <= $baseKm)  return $baseFee;
            if ($km <= $higherKm) return $baseFee + (int) (ceil($km - $baseKm) * $perKm);
            return $baseFee + (int) (($higherKm - $baseKm) * $perKm) + (int) (ceil($km - $higherKm) * $higherPer);
        }

        if ($km <= 2)  return 15_000;
        if ($km <= 10) return 15_000 + (int) (ceil($km - 2) * 5_000);
        return 15_000 + (8 * 5_000) + (int) (ceil($km - 10) * 6_000);
    }

    // ── Lái hộ xe máy ─────────────────────────────────────────────────────────
    private static function motorFee(float $km, ?int $cityId = null): int
    {
        $cfg = self::cfg('motor', $cityId);
        if ($cfg && ($cfg['type'] ?? '') === 'linear') return self::linearFee($km, $cfg);

        return $km <= 3 ? 60_000 : 60_000 + (int) (ceil($km - 3) * 6_000);
    }

    // ── Lái hộ ô tô ───────────────────────────────────────────────────────────
    private static function carFee(float $km, ?int $cityId = null): int
    {
        $cfg = self::cfg('car', $cityId);
        if ($cfg && ($cfg['type'] ?? '') === 'linear') return self::linearFee($km, $cfg);

        return $km <= 3 ? 80_000 : 80_000 + (int) (ceil($km - 3) * 10_000);
    }

    private static function linearFee(float $km, array $cfg): int
    {
        $baseKm  = (float) $cfg['base_km'];
        $baseFee = (int)   $cfg['base_fee'];
        $perKm   = (int)   $cfg['per_km_fee'];

        return $km <= $baseKm ? $baseFee : $baseFee + (int) (ceil($km - $baseKm) * $perKm);
    }

    // ── Nạp tiền ──────────────────────────────────────────────────────────────
    public static function topupFee(int $amount, ?int $cityId = null): int
    {
        $cfg = self::cfg('topup', $cityId);

        if ($cfg && ($cfg['type'] ?? '') === 'topup') {
            foreach ($cfg['tiers'] as $tier) {
                if ($amount < $tier['max_amount']) return (int) $tier['fee'];
            }
            $last    = end($cfg['tiers']);
            $perUnit = (int) ($cfg['over_max_per_unit'] ?? 5_000_000);
            $step    = (int) ($cfg['over_max_fee_step'] ?? 10_000);
            return (int) $last['fee'] + (int) (ceil(($amount - $last['max_amount']) / $perUnit) * $step);
        }

        if ($amount < 5_000_000)  return 20_000;
        if ($amount < 10_000_000) return 30_000;
        if ($amount < 15_000_000) return 40_000;
        if ($amount < 20_000_000) return 50_000;
        if ($amount < 25_000_000) return 60_000;
        return 60_000 + (int) (ceil(($amount - 25_000_000) / 5_000_000) * 10_000);
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    public static function nightSurcharge(): int
    {
        $hour = (int) now()->format('G');
        if ($hour === 23 || $hour === 0) return 5_000;
        if ($hour >= 1 && $hour <= 3)   return 10_000;
        return 0;
    }

    public static function estimate(string $serviceType, float $distanceKm, ?int $cityId = null): array
    {
        $base = match ($serviceType) {
            'delivery', 'shopping' => self::slabFee($distanceKm, $serviceType, $cityId),
            'bike'                 => self::bikeFee($distanceKm, $cityId),
            'motor'                => self::motorFee($distanceKm, $cityId),
            'car'                  => self::carFee($distanceKm, $cityId),
            default                => self::slabFee($distanceKm, 'delivery', $cityId),
        };

        $surcharge = self::nightSurcharge();

        return [
            'service_type'   => $serviceType,
            'distance_km'    => round($distanceKm, 2),
            'fee'            => $base + $surcharge,
            'night_surcharge' => $surcharge,
        ];
    }

    public static function estimateFromCoords(
        string $serviceType,
        float $pickupLat, float $pickupLng,
        float $deliveryLat, float $deliveryLng,
        ?int $cityId = null
    ): array {
        $distance = VietmapService::roadDistanceKm($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);
        return self::estimate($serviceType, $distance, $cityId);
    }

    public static function estimateFromAddresses(
        string $serviceType,
        string $pickupAddress,
        string $deliveryAddress,
        ?string $cityName = null,
        ?int $cityId = null
    ): array {
        $result = self::estimate($serviceType, 3.0, $cityId);
        $result['geocode_failed'] = true;
        return $result;
    }

    public static function serviceTypes(): array
    {
        return ['delivery', 'shopping', 'topup', 'bike', 'motor', 'car'];
    }
}
