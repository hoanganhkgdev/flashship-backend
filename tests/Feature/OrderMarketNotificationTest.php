<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderMarketService;
use Tests\TestCase;

class OrderMarketNotificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_market_notification_only_targets_nearby_idle_drivers(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $city = City::create(['name' => 'Market notify', 'slug' => 'market-notify-'.uniqid(), 'is_active' => true]);
        $eligible = $this->driver($city, 'eligible-token');
        $far = $this->driver($city, 'far-token');
        $noSession = $this->driver($city, 'no-session-token');
        $full = $this->driver($city, 'full-token');

        $this->activeOrder($city, $eligible);
        $this->activeOrder($city, $far);
        $this->activeOrder($city, $full);

        $marketOrder = Order::create([
            'status' => 'pending', 'city_id' => $city->id, 'service_type' => 'delivery', 'platform' => 'customer_app',
            'pickup_address' => 'Rạch Giá', 'delivery_address' => 'Điểm giao', 'pickup_lat' => 10.0, 'pickup_lng' => 105.0,
        ]);

        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->once()->andReturn([
            $eligible->id => ['lat' => 10.005, 'lng' => 105.0, 'bearing' => null],
            $far->id => ['lat' => 10.2, 'lng' => 105.0, 'bearing' => null],
            $noSession->id => ['lat' => 10.004, 'lng' => 105.0, 'bearing' => null],
            $full->id => ['lat' => 10.003, 'lng' => 105.0, 'bearing' => null],
        ]);

        $tokens = (new OrderMarketService($locations))->eligibleNotificationTokens($marketOrder);

        $this->assertSame(['no-session-token'], $tokens);
    }

    private function driver(City $city, string $token): User
    {
        return User::create([
            'name' => $token, 'email' => $token.'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true,
            'city_id' => $city->id, 'fcm_token' => $token,
        ]);
    }

    private function activeOrder(City $city, User $driver): Order
    {
        return Order::create([
            'status' => 'assigned', 'city_id' => $city->id, 'service_type' => 'delivery', 'platform' => 'customer_app',
            'pickup_address' => 'a', 'delivery_address' => 'b', 'delivery_man_id' => $driver->id,
            'driver_order_role' => 'market',
        ]);
    }
}
