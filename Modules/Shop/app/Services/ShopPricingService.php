<?php

namespace Modules\Shop\Services;

use Modules\Core\Services\VietmapService;

class ShopPricingService
{
    // ── Slab tables ────────────────────────────────────────────────────────────

    // Đồ ăn / nước uống  &  Bưu kiện (base, trước weight surcharge)
    private static array $slabDefault = [
        ['max_km' => 3.4,  'fee' => 10_000],
        ['max_km' => 4.4,  'fee' => 13_000],
        ['max_km' => 5.4,  'fee' => 15_000],
        ['max_km' => 6.4,  'fee' => 18_000],
        ['max_km' => 7.4,  'fee' => 20_000],
        ['max_km' => 8.4,  'fee' => 23_000],
        ['max_km' => 10.0, 'fee' => 25_000],
    ];

    // Giỏ hoa / trái cây / bó hoa
    private static array $slabFlowers = [
        ['max_km' => 3.4,  'fee' => 15_000],
        ['max_km' => 4.4,  'fee' => 18_000],
        ['max_km' => 5.4,  'fee' => 20_000],
        ['max_km' => 6.4,  'fee' => 23_000],
        ['max_km' => 7.4,  'fee' => 25_000],
        ['max_km' => 8.4,  'fee' => 28_000],
        ['max_km' => 10.0, 'fee' => 30_000],
    ];

    // Phụ phí vượt 10km (mỗi km thêm)
    private const OVER_MAX_PER_KM = 3_000;

    // Bưu kiện: phụ phí theo kg (làm tròn lên)
    private const PARCEL_PER_KG = 1_000;

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Tính phí từ tọa độ.
     */
    public static function estimateFromCoords(
        string $cargoType,
        float $pickupLat, float $pickupLng,
        float $deliveryLat, float $deliveryLng,
        ?float $cargoWeightKg = null
    ): array {
        $km = VietmapService::roadDistanceKm($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);
        return self::calculate($cargoType, $km, $cargoWeightKg);
    }

    /**
     * Tính phí từ địa chỉ text (geocode → roadDistance).
     */
    public static function estimateFromAddresses(
        string $cargoType,
        string $pickupAddress,
        string $deliveryAddress,
        ?float $cargoWeightKg = null
    ): array {
        $pickup   = VietmapService::geocode($pickupAddress);
        $delivery = VietmapService::geocode($deliveryAddress);

        if ($pickup && $delivery) {
            return self::estimateFromCoords(
                $cargoType,
                $pickup['lat'],   $pickup['lng'],
                $delivery['lat'], $delivery['lng'],
                $cargoWeightKg
            );
        }

        // Fallback 3km nếu geocode thất bại
        $result = self::calculate($cargoType, 3.0, $cargoWeightKg);
        $result['geocode_failed'] = true;
        return $result;
    }

    // ── Internal ───────────────────────────────────────────────────────────────

    private static function calculate(string $cargoType, float $km, ?float $weightKg): array
    {
        $slabs   = $cargoType === 'flowers' ? self::$slabFlowers : self::$slabDefault;
        $baseFee = self::slabFee($km, $slabs);

        // Bưu kiện: cộng phụ phí theo kg
        $weightSurcharge = 0;
        if ($cargoType === 'parcel' && $weightKg !== null && $weightKg > 0) {
            $weightSurcharge = (int) (ceil($weightKg) * self::PARCEL_PER_KG);
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

    private static function slabFee(float $km, array $slabs): int
    {
        foreach ($slabs as $slab) {
            if ($km <= $slab['max_km']) {
                return (int) $slab['fee'];
            }
        }
        // Vượt 10km: lấy mức cao nhất + (km vượt × 3k/km)
        $last    = end($slabs);
        $overKm  = $km - $last['max_km'];
        return (int) $last['fee'] + (int) (ceil($overKm) * self::OVER_MAX_PER_KM);
    }

    private static function nightSurcharge(): int
    {
        $hour = (int) now()->format('G');
        if ($hour === 23 || $hour === 0) return 5_000;
        if ($hour >= 1 && $hour <= 3)   return 10_000;
        return 0;
    }
}
