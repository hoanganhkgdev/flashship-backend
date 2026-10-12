<?php

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\City;
use Modules\Core\Services\GoogleMapService;

class ShopDestinationPrice extends Model
{
    protected $table = 'shop_destination_prices';

    protected $fillable = [
        'city_id',
        'name',
        'lat',
        'lng',
        'radius_km',
        'fee',
        'fee_max',
        'is_origin',
        'is_active',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'radius_km' => 'float',
        'fee' => 'integer',
        'fee_max' => 'integer',
        'is_origin' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function distanceKmTo(float $lat, float $lng): float
    {
        return GoogleMapService::haversineKm($this->lat, $this->lng, $lat, $lng);
    }

    /**
     * Giá tại một điểm cụ thể trong khu vực. Với bảng giá dạng khoảng, giá
     * tăng tuyến tính từ tâm tới rìa khu vực, làm tròn theo 1.000đ.
     */
    public function feeAt(?float $lat = null, ?float $lng = null): int
    {
        if ($this->fee_max === null || $this->fee_max <= $this->fee || $lat === null || $lng === null) {
            return $this->fee;
        }

        $ratio = min(1.0, $this->distanceKmTo($lat, $lng) / max($this->radius_km, 0.1));
        $rawFee = $this->fee + (($this->fee_max - $this->fee) * $ratio);

        return max($this->fee, min($this->fee_max, (int) (round($rawFee / 1000) * 1000)));
    }

    public static function findActive(?int $cityId, ?int $destinationId): ?self
    {
        if (! $cityId || ! $destinationId) {
            return null;
        }

        return static::where('city_id', $cityId)->where('is_active', true)->find($destinationId);
    }

    /**
     * Điểm giao chưa có địa chỉ/toạ độ mà shop đã chọn khu vực: lấy tên và tâm
     * khu vực làm điểm giao. Có sẵn địa chỉ/toạ độ thì giữ nguyên.
     *
     * @return array{0: ?string, 1: ?float, 2: ?float, 3: ?self, 4: bool} [địa chỉ, lat, lng, khu vực,
     *                                                                    true nếu toạ độ là tâm khu vực]
     */
    public static function fillDeliveryPoint(?int $cityId, ?int $destinationId, ?string $address, $lat, $lng): array
    {
        $zone = static::findActive($cityId, $destinationId);
        if (! $zone) {
            return [$address, $lat, $lng, null, false];
        }

        $hasPoint = $lat !== null && $lng !== null;

        return [
            filled($address) ? $address : $zone->name,
            $hasPoint ? (float) $lat : $zone->lat,
            $hasPoint ? (float) $lng : $zone->lng,
            $zone,
            ! $hasPoint,
        ];
    }

    /**
     * Điểm đến do shop chọn tay. Vẫn phải thuộc thành phố, đang bật, và điểm lấy
     * (nếu biết) nằm trong vùng xuất phát — ngoài vùng thì tính theo km như thường.
     */
    public static function forSelection(
        ?int $cityId,
        ?int $destinationId,
        ?float $pickupLat = null,
        ?float $pickupLng = null,
        ?float $deliveryLat = null,
        ?float $deliveryLng = null
    ): ?self {
        if (! $cityId || ! $destinationId) {
            return null;
        }

        $zone = static::where('city_id', $cityId)->where('is_active', true)->find($destinationId);
        if (! $zone) {
            return null;
        }

        if ($pickupLat !== null && $pickupLng !== null) {
            $origins = static::where('city_id', $cityId)->where('is_active', true)->where('is_origin', true)->get();
            if ($origins->isNotEmpty()
                && ! $origins->contains(fn (self $o) => $o->distanceKmTo($pickupLat, $pickupLng) <= $o->radius_km)) {
                return null;
            }
        }

        // Khi đã có điểm giao thật, không cho destination_id ghi đè một vị trí
        // nằm ngoài khu vực. Caller sẽ tự nhận diện lại theo toạ độ thực tế.
        if ($deliveryLat !== null && $deliveryLng !== null
            && $zone->distanceKmTo($deliveryLat, $deliveryLng) > $zone->radius_km) {
            return null;
        }

        return $zone;
    }

    /**
     * Giá cố định theo điểm giao. Khu vực có điểm xuất phát thì chỉ áp khi điểm
     * lấy hàng nằm trong đó; điểm giao thuộc nhiều vùng thì lấy vùng có tâm gần
     * nhất. Không khớp trả null để rơi về giá theo km.
     */
    public static function resolve(
        ?int $cityId,
        float $pickupLat, float $pickupLng,
        float $deliveryLat, float $deliveryLng
    ): ?self {
        if (! $cityId) {
            return null;
        }

        $zones = static::where('city_id', $cityId)->where('is_active', true)->get();
        if ($zones->isEmpty()) {
            return null;
        }

        $origins = $zones->where('is_origin', true);
        if ($origins->isNotEmpty()
            && ! $origins->contains(fn (self $z) => $z->distanceKmTo($pickupLat, $pickupLng) <= $z->radius_km)) {
            return null;
        }

        return $zones
            ->map(fn (self $z) => ['zone' => $z, 'km' => $z->distanceKmTo($deliveryLat, $deliveryLng)])
            ->filter(fn (array $m) => $m['km'] <= $m['zone']->radius_km)
            ->sortBy('km')
            ->first()['zone'] ?? null;
    }
}
