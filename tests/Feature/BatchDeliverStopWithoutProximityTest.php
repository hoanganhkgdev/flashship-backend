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
use Tests\TestCase;

class BatchDeliverStopWithoutProximityTest extends TestCase
{
    use DatabaseTransactions;

    private function driverFarFromEverything(): User
    {
        $city = City::create(['name' => 'Batch prox', 'slug' => 'batch-prox-'.uniqid(), 'is_active' => true]);
        $driver = User::create([
            'name' => 'Driver', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true, 'city_id' => $city->id,
        ]);
        // Cách điểm giao hàng chục km.
        $this->mock(DriverLocationService::class)->shouldReceive('freshLocationsFor')->andReturn([
            $driver->id => ['lat' => 11.0, 'lng' => 106.0, 'bearing' => null],
        ]);

        return $driver;
    }

    private function order(User $driver, bool $batch): Order
    {
        $stops = [
            ['seq' => 1, 'address' => 'Điểm 1', 'lat' => 10.0, 'lng' => 105.0, 'fee' => 15000, 'delivered_at' => null],
            ['seq' => 2, 'address' => 'Điểm 2', 'lat' => 10.1, 'lng' => 105.1, 'fee' => 20000, 'delivered_at' => null],
        ];

        return Order::create([
            'code' => 'T'.random_int(100000, 999999),
            'status' => 'processing', 'city_id' => $driver->city_id, 'service_type' => 'delivery', 'platform' => 'shop_app',
            'delivery_man_id' => $driver->id, 'pickup_address' => 'Shop', 'delivery_address' => 'Điểm 1',
            'delivery_lat' => 10.0, 'delivery_lng' => 105.0, 'shipping_fee' => 35000,
            'is_batch' => $batch, 'stops' => $batch ? $stops : null,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Queue::fake();
        Event::fake();
        Http::fake(['*' => Http::response([], 500)]);
    }

    public function test_batch_stop_can_be_delivered_even_when_the_driver_is_far_away(): void
    {
        $driver = $this->driverFarFromEverything();
        $order = $this->order($driver, true)->fresh();
        Sanctum::actingAs($driver);

        $response = $this->postJson("/api/orders/{$order->id}/stops/1/deliver");

        $response->assertOk()->assertJsonPath('completed', false);
        $this->assertNotNull(collect($order->fresh()->stops)->firstWhere('seq', 1)['delivered_at']);
    }

    public function test_finishing_the_last_batch_stop_completes_the_order_from_anywhere(): void
    {
        $driver = $this->driverFarFromEverything();
        $order = $this->order($driver, true)->fresh();
        Sanctum::actingAs($driver);

        $this->postJson("/api/orders/{$order->id}/stops/1/deliver")->assertOk();
        $this->postJson("/api/orders/{$order->id}/stops/2/deliver")->assertOk()->assertJsonPath('completed', true);

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_delivery_order_that_is_not_batch_can_complete_from_anywhere(): void
    {
        $driver = $this->driverFarFromEverything();
        $order = $this->order($driver, false)->fresh();
        Sanctum::actingAs($driver);

        $this->postJson("/api/orders/{$order->id}/complete")->assertOk();

        $this->assertSame('completed', $order->fresh()->status);
    }
}
