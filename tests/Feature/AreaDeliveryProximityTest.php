<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Shop\Models\ShopDestinationPrice;
use Tests\TestCase;

class AreaDeliveryProximityTest extends TestCase
{
    use DatabaseTransactions;

    private const TARGET = [10.0, 105.0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
    }

    private function driverAt(float $lat, float $lng): User
    {
        $city = City::create(['name' => 'Prox', 'slug' => 'prox-'.uniqid(), 'is_active' => true]);
        $driver = User::create([
            'name' => 'Driver', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'city_id' => $city->id,
        ]);
        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->andReturn([
            $driver->id => ['lat' => $lat, 'lng' => $lng, 'bearing' => null],
        ]);

        return $driver;
    }

    // 0.01 độ vĩ ≈ 1.11 km.
    public function test_default_radius_rejects_a_driver_more_than_300m_away(): void
    {
        $driver = $this->driverAt(10.0135, 105.0);

        $error = app(OrderService::class)->checkDriverProximity($driver, self::TARGET[0], self::TARGET[1], $driver->city_id);

        $this->assertSame('TOO_FAR_FROM_DELIVERY', $error['reason_code']);
    }

    public function test_area_radius_lets_a_driver_complete_anywhere_inside_the_area(): void
    {
        $driver = $this->driverAt(10.0135, 105.0);

        $error = app(OrderService::class)->checkDriverProximity($driver, self::TARGET[0], self::TARGET[1], $driver->city_id, 2.0);

        $this->assertNull($error);
    }

    public function test_area_radius_still_rejects_a_driver_outside_the_area(): void
    {
        $driver = $this->driverAt(10.03, 105.0);

        $error = app(OrderService::class)->checkDriverProximity($driver, self::TARGET[0], self::TARGET[1], $driver->city_id, 2.0);

        $this->assertSame('TOO_FAR_FROM_DELIVERY', $error['reason_code']);
        $this->assertStringContainsString('2000 m', $error['message']);
    }

    public function test_real_delivery_address_keeps_no_area_radius(): void
    {
        Queue::fake();
        Event::fake();
        Http::fake(['*' => Http::response([], 500)]);
        $city = City::create(['name' => 'Real addr', 'slug' => 'real-addr-'.uniqid(), 'is_active' => true]);
        ShopDestinationPrice::create(['city_id' => $city->id, 'name' => 'Sân bay', 'lat' => 10.01, 'lng' => 105.01, 'radius_km' => 2, 'fee' => 55000]);
        $shop = User::create([
            'name' => 'Shop', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'shop', 'status' => 1, 'city_id' => $city->id,
        ]);
        Sanctum::actingAs($shop);

        $this->postJson('/api/shop/orders', [
            'pickup_address' => 'Cửa hàng', 'pickup_lat' => 10.0, 'pickup_lng' => 105.0,
            'delivery_address' => 'Số 1 đường A', 'delivery_lat' => 10.011, 'delivery_lng' => 105.011,
            'cargo_type' => 'food', 'destination_id' => ShopDestinationPrice::where('city_id', $city->id)->value('id'),
        ])->assertStatus(201);

        $this->assertNull(Order::latest('id')->first()->delivery_area_radius_km);
    }
}
