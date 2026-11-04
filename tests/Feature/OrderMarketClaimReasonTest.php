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

/** Nhận đơn: lý do từ chối cụ thể, nhận lại khi đơn đã đang giao, giới hạn đơn trong hồ sơ. */
class OrderMarketClaimReasonTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Reason market', 'slug' => 'reason-'.uniqid(), 'is_active' => true]);
    }

    private function driver(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'Driver', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true,
            'city_id' => $this->city->id,
        ], $attrs));
    }

    private function openOrder(array $attrs = []): Order
    {
        $order = Order::create(array_merge([
            'code' => 'RSN-'.uniqid(), 'status' => 'pending', 'city_id' => $this->city->id,
            'service_type' => 'delivery', 'platform' => 'customer_app',
            'pickup_address' => 'Điểm lấy', 'delivery_address' => 'Điểm giao',
        ], $attrs));
        OrderMarketListing::create([
            'order_id' => $order->id, 'city_id' => $this->city->id, 'status' => 'open',
            'open_reason' => 'self_pick', 'offer_attempts' => 0, 'opened_at' => now(),
        ]);

        return $order;
    }

    private function market(): OrderMarketService
    {
        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->zeroOrMoreTimes()->andReturn([]);

        return new OrderMarketService($locations);
    }

    public function test_offline_driver_gets_offline_code(): void
    {
        $result = $this->market()->claim($this->openOrder(), $this->driver(['is_online' => false]));

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('offline', $result['code']);
        $this->assertStringContainsString('Offline', $result['message']);
    }

    public function test_car_order_without_license_gets_specific_code(): void
    {
        $result = $this->market()->claim($this->openOrder(['service_type' => 'car']), $this->driver(['has_car_license' => false]));

        $this->assertSame('no_car_license', $result['code']);
    }

    public function test_taken_order_returns_taken_code(): void
    {
        $order = $this->openOrder();
        $market = $this->market();
        $this->assertTrue($market->claim($order, $this->driver())['success']);

        $second = $market->claim($order->fresh(), $this->driver());
        $this->assertSame(409, $second['status']);
        $this->assertSame('taken', $second['code']);
    }

    public function test_retry_after_pickup_is_still_success(): void
    {
        $order = $this->openOrder();
        $driver = $this->driver();
        $market = $this->market();
        $this->assertTrue($market->claim($order, $driver)['success']);
        $order->refresh()->update(['status' => 'processing']);

        $retry = $market->claim($order->fresh(), $driver->fresh());
        $this->assertTrue($retry['success']);
        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_profile_returns_max_active_orders(): void
    {
        $driver = $this->driver();
        $this->actingAs($driver, 'sanctum')->getJson('/api/driver/profile')
            ->assertOk()
            ->assertJsonPath('data.user.max_active_orders', \Modules\Core\Services\OperationalSettings::maxActiveOrdersPerDriver($this->city->id));
    }

    public function test_toggling_rain_mode_resyncs_city_market(): void
    {
        $market = $this->mock(OrderMarketService::class);
        $market->shouldReceive('syncCity')->once()->with($this->city->id);

        $this->city->update(['is_rain_mode' => true]);
        $this->city->update(['name' => 'Đổi tên không đồng bộ']);
    }
}
