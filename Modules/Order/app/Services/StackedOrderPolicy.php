<?php

namespace Modules\Order\Services;

use Modules\Core\Services\GoogleMapService;
use Modules\Order\Models\Order;

class StackedOrderPolicy
{
    public const MAX_PICKUP_KM = 1.0;

    public const MAX_DELIVERY_KM = 1.5;

    /**
     * Chỉ ghép khi tài xế chưa lấy đơn thứ nhất. Nếu đơn thứ nhất đã chuyển
     * processing, phát thêm đơn gần điểm lấy cũ sẽ buộc tài xế quay ngược lại.
     */
    public static function allows(Order $incoming, Order $active): bool
    {
        if ($active->status !== 'assigned') {
            return false;
        }

        $coordinates = [
            $incoming->pickup_lat,
            $incoming->pickup_lng,
            $incoming->delivery_lat,
            $incoming->delivery_lng,
            $active->pickup_lat,
            $active->pickup_lng,
            $active->delivery_lat,
            $active->delivery_lng,
        ];
        if (collect($coordinates)->contains(fn ($value) => ! is_numeric($value))) {
            return false;
        }

        $pickupDistance = GoogleMapService::haversineKm(
            (float) $incoming->pickup_lat,
            (float) $incoming->pickup_lng,
            (float) $active->pickup_lat,
            (float) $active->pickup_lng,
        );
        $deliveryDistance = GoogleMapService::haversineKm(
            (float) $incoming->delivery_lat,
            (float) $incoming->delivery_lng,
            (float) $active->delivery_lat,
            (float) $active->delivery_lng,
        );

        return $pickupDistance <= self::MAX_PICKUP_KM
            && $deliveryDistance <= self::MAX_DELIVERY_KM;
    }
}
