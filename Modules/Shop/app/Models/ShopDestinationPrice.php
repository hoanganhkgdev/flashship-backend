<?php

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;
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
        return $this->belongsTo(\Modules\Core\Models\City::class);
    }

    public function distanceKmTo(float $lat, float $lng): float
    {
        return GoogleMapService::haversineKm($this->lat, $this->lng, $lat, $lng);
    }

    /**
     * Điểm đến do shop chọn tay. Vẫn phải thuộc thành phố, đang bật, và điểm lấy
     * (nếu biết) nằm trong vùng xuất phát — ngoài vùng thì tính theo km như thường.
     */
    public static function forSelection(?int $cityId, ?int $destinationId, ?float $pickupLat = null, ?float $pickupLng = null): ?self
    {
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
