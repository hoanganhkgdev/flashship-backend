<?php

namespace Modules\Order\Services;

use Illuminate\Support\Collection;
use Modules\Core\Services\GoogleMapService;
use Modules\Core\Services\OperationalSettings;
use Modules\Order\Models\Order;

class StackedOrderPolicy
{
    /** Đơn mới phải cùng tuyến với tất cả đơn tài xế đang giữ. */
    public static function allowsAll(Order $incoming, Collection $activeOrders): bool
    {
        return $activeOrders->every(fn (Order $active) => self::allows($incoming, $active));
    }

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

        return $pickupDistance <= OperationalSettings::stackMaxPickupKm($incoming->city_id)
            && $deliveryDistance <= OperationalSettings::stackMaxDeliveryKm($incoming->city_id);
    }
}
