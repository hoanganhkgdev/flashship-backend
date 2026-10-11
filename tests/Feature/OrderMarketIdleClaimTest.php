<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderMarketListing;
use Modules\Order\Services\OrderMarketService;
use Tests\TestCase;

class OrderMarketIdleClaimTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('dispatch.self_pick_enabled', true);
    }

    public function test_system_assigned_city_rejects_direct_market_claim(): void
    {
        $city = City::create(['name' => 'System dispatch', 'slug' => 'system-dispatch-'.uniqid(), 'is_active' => true]);
        $driver = User::create([
            'name' => 'System driver', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true, 'city_id' => $city->id,
        ]);
        $order = $this->marketOrder($city, 'delivery');
        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldNotReceive('freshLocationsFor');

        $result = (new OrderMarketService($locations))->claim($order, $driver);

        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status']);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_idle_driver_claims_market_order_without_main_or_extra_role(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Redis::shouldReceive('del')->once();

        $city = City::create(['name' => 'Idle market', 'slug' => 'idle-market-'.uniqid(), 'is_active' => true]);
        $this->enableSelfPick($city);
        $customer = User::create([
            'name' => 'Market customer', 'email' => uniqid().'@test.local', 'phone' => '08'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'customer', 'status' => 1, 'city_id' => $city->id,
        ]);
        $driver = User::create([
            'name' => 'Idle driver', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true, 'city_id' => $city->id,
        ]);
        $order = Order::create([
            'code' => 'MKT-'.uniqid(), 'status' => 'pending', 'city_id' => $city->id,
            'service_type' => 'delivery', 'platform' => 'customer_app',
            'sender_platform_id' => $customer->id,
            'pickup_address' => 'Điểm lấy', 'delivery_address' => 'Điểm giao',
            'pickup_lat' => 10.0, 'pickup_lng' => 105.0,
        ]);
        OrderMarketListing::create([
            'order_id' => $order->id, 'city_id' => $city->id, 'status' => 'open',
            'open_reason' => 'timeout', 'offer_attempts' => 0, 'opened_at' => now(), 'expires_at' => now()->addMinutes(10),
        ]);

        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->once()->with([$driver->id])->andReturn([
            $driver->id => ['lat' => 10.001, 'lng' => 105.0, 'bearing' => null],
        ]);

        $result = (new OrderMarketService($locations))->claim($order, $driver);
        $fresh = $order->fresh();

        $this->assertTrue($result['success']);
        $this->assertSame('assigned', $fresh->status);
        $this->assertSame($driver->id, $fresh->delivery_man_id);
        $this->assertSame('market', $fresh->driver_order_role);
        $this->assertNull($fresh->main_order_id);
        $this->assertSame(0, (int) $fresh->extra_claimed_count);
        $this->assertNull($fresh->bundle_window_expires_at);
        $this->assertSame('claimed', $order->marketListing()->first()->status);
        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => $customer->id,
            'type' => 'order_status',
            'order_code' => $order->code,
        ]);
    }

    public function test_driver_without_car_license_does_not_see_car_order_in_market(): void
    {
        $city = City::create(['name' => 'Car market', 'slug' => 'car-market-'.uniqid(), 'is_active' => true]);
        DB::table(OperationalSettings::CITY_TABLE)->where('city_id', $city->id)
            ->where('key', 'dispatch.mode')->update(['value' => 'self_pick', 'updated_at' => now()]);
        OperationalSettings::flush($city->id);

        $driver = User::create([
            'name' => 'Bike driver', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true,
            'city_id' => $city->id, 'has_car_license' => false,
        ]);
        $delivery = $this->marketOrder($city, 'delivery');
        $car = $this->marketOrder($city, 'car');

        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->once()->with([$driver->id])->andReturn([
            $driver->id => ['lat' => 10.001, 'lng' => 105.0, 'bearing' => null],
        ]);

        $orders = (new OrderMarketService($locations))->listForDriver($driver);

        $this->assertSame([$delivery->id], collect($orders)->pluck('id')->all());
        $this->assertNotContains($car->id, collect($orders)->pluck('id')->all());
    }

    public function test_busy_driver_cannot_claim_a_self_pick_order(): void
    {
        $city = City::create(['name' => 'Simple market', 'slug' => 'simple-market-'.uniqid(), 'is_active' => true]);
        $this->enableSelfPick($city);
        $driver = User::create([
            'name' => 'Two orders', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true, 'city_id' => $city->id,
        ]);
        foreach (range(1, 2) as $index) {
            Order::create([
                'code' => "ACTIVE-{$index}-".uniqid(), 'status' => 'assigned', 'city_id' => $city->id,
                'service_type' => 'delivery', 'platform' => 'customer_app', 'delivery_man_id' => $driver->id,
                'pickup_address' => 'a', 'delivery_address' => 'b', 'driver_order_role' => null,
            ]);
        }
        $order = $this->marketOrder($city, 'delivery');
        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->once()->with([$driver->id])->andReturn([
            $driver->id => ['lat' => 10.001, 'lng' => 105.0, 'bearing' => null],
        ]);

        $result = (new OrderMarketService($locations))->claim($order, $driver);

        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status']);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertCount(2, Order::where('delivery_man_id', $driver->id)->whereIn('status', ['assigned', 'processing'])->get());
    }

    private function marketOrder(City $city, string $serviceType): Order
    {
        $order = Order::create([
            'code' => strtoupper($serviceType).'-'.uniqid(), 'status' => 'pending', 'city_id' => $city->id,
            'service_type' => $serviceType, 'platform' => 'customer_app',
            'pickup_address' => 'Điểm lấy', 'delivery_address' => 'Điểm giao',
            'pickup_lat' => 10.0, 'pickup_lng' => 105.0,
        ]);
        OrderMarketListing::create([
            'order_id' => $order->id, 'city_id' => $city->id, 'status' => 'open',
            'open_reason' => 'timeout', 'offer_attempts' => 0, 'opened_at' => now(), 'expires_at' => now()->addMinutes(10),
        ]);

        return $order;
    }

    private function enableSelfPick(City $city): void
    {
        DB::table(OperationalSettings::CITY_TABLE)->where('city_id', $city->id)
            ->where('key', 'dispatch.mode')->update(['value' => 'self_pick', 'updated_at' => now()]);
        OperationalSettings::flush($city->id);
    }
}
