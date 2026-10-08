<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
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

    public function test_idle_driver_claims_market_order_as_main(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Redis::shouldReceive('del')->once();

        $city = City::create(['name' => 'Idle market', 'slug' => 'idle-market-'.uniqid(), 'is_active' => true]);
        $driver = User::create([
            'name' => 'Idle driver', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true, 'city_id' => $city->id,
        ]);
        $order = Order::create([
            'code' => 'MKT-'.uniqid(), 'status' => 'pending', 'city_id' => $city->id,
            'service_type' => 'delivery', 'platform' => 'customer_app',
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
        $this->assertSame('main', $fresh->driver_order_role);
        $this->assertNull($fresh->main_order_id);
        $this->assertSame(0, (int) $fresh->extra_claimed_count);
        $this->assertNotNull($fresh->bundle_window_expires_at);
        $this->assertSame('claimed', $order->marketListing()->first()->status);
    }
}
