<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderMarketListing;
use Modules\Order\Services\OrderMarketService;
use Tests\TestCase;

class OrderMarketIdleClaimTest extends TestCase
{
    use DatabaseTransactions;

    public function test_idle_driver_claims_market_order_without_main_or_extra_role(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());

        $city = City::create([
            'name' => 'Idle market', 'slug' => 'idle-market-'.uniqid(),
            'is_active' => true, 'is_rain_mode' => true,
        ]);
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
            'order_note' => 'Gọi khách trước khi giao',
            'is_freeship' => true,
            'voucher_code' => 'GIAM20',
            'discount_amount' => 20000,
            'bonus_fee' => 3000,
            'night_surcharge' => 7000,
        ]);
        $listing = OrderMarketListing::create([
            'order_id' => $order->id, 'city_id' => $city->id, 'status' => 'open',
            'open_reason' => 'timeout', 'offer_attempts' => 0, 'opened_at' => now(), 'expires_at' => now()->addMinutes(10),
        ]);

        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->zeroOrMoreTimes()->with([$driver->id])->andReturn([
            $driver->id => ['lat' => 10.001, 'lng' => 105.0, 'bearing' => null],
        ]);

        $market = new OrderMarketService($locations);
        $payload = $market->payloadFor($order, $listing);
        $this->assertSame('customer_app', $payload['platform']);
        $this->assertSame('Gọi khách trước khi giao', $payload['order_note']);
        $this->assertTrue($payload['is_freeship']);
        $this->assertSame('GIAM20', $payload['voucher_code']);
        $this->assertSame(20000, $payload['discount_amount']);
        $this->assertSame(3000, $payload['bonus_fee']);
        $this->assertSame(7000, $payload['night_surcharge']);
        $this->assertTrue($payload['is_rain_mode']);
        $this->assertGreaterThan(0, $payload['rain_bonus_amount']);

        $result = $market->claim($order, $driver);
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

    public function test_same_driver_can_retry_claim_without_duplicate_side_effects(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());

        $city = City::create(['name' => 'Retry market', 'slug' => 'retry-market-'.uniqid(), 'is_active' => true]);
        $customer = User::create([
            'name' => 'Retry customer', 'email' => uniqid().'@test.local', 'phone' => '08'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'customer', 'status' => 1, 'city_id' => $city->id,
        ]);
        $driver = User::create([
            'name' => 'Retry driver', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true, 'city_id' => $city->id,
        ]);
        $order = Order::create([
            'code' => 'RETRY-'.uniqid(), 'status' => 'pending', 'city_id' => $city->id,
            'service_type' => 'delivery', 'platform' => 'customer_app', 'sender_platform_id' => $customer->id,
            'pickup_address' => 'Điểm lấy', 'delivery_address' => 'Điểm giao',
            'pickup_lat' => 10.0, 'pickup_lng' => 105.0,
        ]);
        OrderMarketListing::create([
            'order_id' => $order->id, 'city_id' => $city->id, 'status' => 'open',
            'open_reason' => 'self_pick', 'offer_attempts' => 0, 'opened_at' => now(), 'expires_at' => now()->addMinutes(10),
        ]);

        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->zeroOrMoreTimes()->with([$driver->id])->andReturn([
            $driver->id => ['lat' => 10.001, 'lng' => 105.0, 'bearing' => null],
        ]);
        $market = new OrderMarketService($locations);

        $first = $market->claim($order, $driver);
        $retry = $market->claim($order->fresh(), $driver->fresh());

        $this->assertTrue($first['success']);
        $this->assertTrue($retry['success']);
        $this->assertSame($order->id, $retry['order']['id']);
        $this->assertSame(1, DB::table('order_histories')->where('order_id', $order->id)->where('type', 'market_claimed')->count());
        $this->assertSame(1, DB::table('customer_notifications')->where('user_id', $customer->id)
            ->where('type', 'order_status')->where('order_code', $order->code)->count());
    }

    public function test_first_driver_keeps_order_when_another_driver_claims_afterward(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());

        $city = City::create(['name' => 'Claim race', 'slug' => 'claim-race-'.uniqid(), 'is_active' => true]);
        $winner = User::create([
            'name' => 'Winner', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true, 'city_id' => $city->id,
        ]);
        $loser = User::create([
            'name' => 'Loser', 'email' => uniqid().'@test.local', 'phone' => '08'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true, 'city_id' => $city->id,
        ]);
        $order = $this->marketOrder($city, 'delivery');

        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->zeroOrMoreTimes()->with([$winner->id])->andReturn([
            $winner->id => ['lat' => 10.001, 'lng' => 105.0, 'bearing' => null],
        ]);
        $locations->shouldReceive('freshLocationsFor')->zeroOrMoreTimes()->with([$loser->id])->andReturn([
            $loser->id => ['lat' => 10.001, 'lng' => 105.0, 'bearing' => null],
        ]);
        $market = new OrderMarketService($locations);

        $won = $market->claim($order, $winner);
        $lost = $market->claim($order->fresh(), $loser);

        $this->assertTrue($won['success']);
        $this->assertFalse($lost['success']);
        $this->assertSame(409, $lost['status']);
        $this->assertSame('Đơn này vừa được tài xế khác nhận.', $lost['message']);
        $this->assertSame($winner->id, $order->fresh()->delivery_man_id);
        $this->assertSame($winner->id, $order->marketListing()->value('claimed_by'));
        $this->assertSame(1, DB::table('order_histories')->where('order_id', $order->id)->where('type', 'market_claimed')->count());
    }

    public function test_driver_without_car_license_does_not_see_car_order_in_market(): void
    {
        $city = City::create(['name' => 'Car market', 'slug' => 'car-market-'.uniqid(), 'is_active' => true]);
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

    public function test_driver_at_active_order_limit_cannot_claim_a_self_pick_order(): void
    {
        $city = City::create(['name' => 'Simple market', 'slug' => 'simple-market-'.uniqid(), 'is_active' => true]);
        $driver = User::create([
            'name' => 'Three orders', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true, 'city_id' => $city->id,
        ]);
        foreach (range(1, 3) as $index) {
            Order::create([
                'code' => "ACTIVE-{$index}-".uniqid(), 'status' => 'assigned', 'city_id' => $city->id,
                'service_type' => 'delivery', 'platform' => 'customer_app', 'delivery_man_id' => $driver->id,
                'pickup_address' => 'a', 'delivery_address' => 'b', 'driver_order_role' => null,
            ]);
        }
        $order = $this->marketOrder($city, 'delivery');
        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->zeroOrMoreTimes()->with([$driver->id])->andReturn([
            $driver->id => ['lat' => 10.001, 'lng' => 105.0, 'bearing' => null],
        ]);

        $result = (new OrderMarketService($locations))->claim($order, $driver);

        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status']);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertCount(3, Order::where('delivery_man_id', $driver->id)->whereIn('status', ['assigned', 'processing'])->get());
    }

    public function test_driver_below_active_order_limit_can_claim_another_self_pick_order(): void
    {
        $city = City::create(['name' => 'Below limit market', 'slug' => 'below-limit-market-'.uniqid(), 'is_active' => true]);
        $driver = User::create([
            'name' => 'Two orders', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true, 'city_id' => $city->id,
        ]);
        foreach (range(1, 2) as $index) {
            Order::create([
                'code' => "ACTIVE-{$index}-".uniqid(), 'status' => 'processing', 'city_id' => $city->id,
                'service_type' => 'delivery', 'platform' => 'customer_app', 'delivery_man_id' => $driver->id,
                'pickup_address' => 'a', 'delivery_address' => 'b', 'driver_order_role' => null,
            ]);
        }
        $order = $this->marketOrder($city, 'delivery');
        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->with([$driver->id])->andReturn([
            $driver->id => ['lat' => 10.001, 'lng' => 105.0, 'bearing' => null],
        ]);
        $service = new OrderMarketService($locations);

        $this->assertSame([$order->id], collect($service->listForDriver($driver))->pluck('id')->all());

        $result = $service->claim($order, $driver);

        $this->assertTrue($result['success']);
        $this->assertSame($driver->id, $order->fresh()->delivery_man_id);
        $this->assertCount(3, Order::where('delivery_man_id', $driver->id)->whereIn('status', ['assigned', 'processing'])->get());
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
}
