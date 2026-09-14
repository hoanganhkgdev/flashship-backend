<?php

namespace Tests\Unit\Order;

use Modules\Order\Models\Order;
use Modules\Order\Services\StackedOrderPolicy;
use PHPUnit\Framework\TestCase;

class StackedOrderPolicyTest extends TestCase
{
    public function test_allows_nearby_route_before_first_pickup(): void
    {
        $active = $this->order('assigned', 10.0000, 105.0000, 10.0500, 105.0500);
        $incoming = $this->order('pending', 10.0050, 105.0000, 10.0550, 105.0500);

        $this->assertTrue(StackedOrderPolicy::allows($incoming, $active));
    }

    public function test_rejects_second_order_after_first_pickup(): void
    {
        $active = $this->order('processing', 10.0000, 105.0000, 10.0500, 105.0500);
        $incoming = $this->order('pending', 10.0050, 105.0000, 10.0550, 105.0500);

        $this->assertFalse(StackedOrderPolicy::allows($incoming, $active));
    }

    public function test_rejects_route_outside_pickup_or_delivery_limit(): void
    {
        $active = $this->order('assigned', 10.0000, 105.0000, 10.0500, 105.0500);

        $farPickup = $this->order('pending', 10.0200, 105.0000, 10.0550, 105.0500);
        $farDelivery = $this->order('pending', 10.0050, 105.0000, 10.0700, 105.0500);

        $this->assertFalse(StackedOrderPolicy::allows($farPickup, $active));
        $this->assertFalse(StackedOrderPolicy::allows($farDelivery, $active));
    }

    private function order(string $status, float $pickupLat, float $pickupLng, float $deliveryLat, float $deliveryLng): Order
    {
        return new Order([
            'status' => $status,
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'delivery_lat' => $deliveryLat,
            'delivery_lng' => $deliveryLng,
        ]);
    }
}
