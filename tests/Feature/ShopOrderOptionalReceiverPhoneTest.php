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
use Modules\Order\Models\Order;
use Tests\TestCase;

class ShopOrderOptionalReceiverPhoneTest extends TestCase
{
    use DatabaseTransactions;

    private function shop(): User
    {
        $city = City::create(['name' => 'Phone optional', 'slug' => 'phone-optional-'.uniqid(), 'is_active' => true]);

        return User::create([
            'name' => 'Shop', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'shop', 'status' => 1, 'city_id' => $city->id,
        ]);
    }

    private function payload(array $override = []): array
    {
        return $override + [
            'pickup_address' => 'Điểm lấy', 'pickup_lat' => 10.0, 'pickup_lng' => 105.0,
            'delivery_address' => 'Điểm giao', 'delivery_lat' => 10.01, 'delivery_lng' => 105.01,
            'cargo_type' => 'food',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Queue::fake();
        Event::fake();
        Http::fake(['*' => Http::response([], 500)]);
    }

    public function test_delivery_order_can_be_created_without_receiver_phone(): void
    {
        Sanctum::actingAs($this->shop());

        $response = $this->postJson('/api/shop/orders', $this->payload());

        $response->assertStatus(201);
        $order = Order::latest('id')->first();
        $this->assertNull($order->delivery_phone);
        $this->assertSame('Điểm giao', $order->delivery_address);
    }

    public function test_receiver_phone_is_still_saved_when_provided(): void
    {
        Sanctum::actingAs($this->shop());

        $this->postJson('/api/shop/orders', $this->payload(['delivery_phone' => '0909123456']))->assertStatus(201);

        $this->assertSame('0909123456', Order::latest('id')->first()->delivery_phone);
    }

    public function test_batch_order_stops_do_not_require_a_phone(): void
    {
        Sanctum::actingAs($this->shop());

        $response = $this->postJson('/api/shop/orders/batch', [
            'pickup_address' => 'Cửa hàng', 'pickup_lat' => 10.0, 'pickup_lng' => 105.0, 'cargo_type' => 'food',
            'stops' => [
                ['address' => 'Điểm 1', 'lat' => 10.01, 'lng' => 105.01],
                ['address' => 'Điểm 2', 'lat' => 10.02, 'lng' => 105.02, 'phone' => '0909123456'],
            ],
        ]);

        $response->assertStatus(201);
        $order = Order::latest('id')->first();
        $this->assertNull($order->delivery_phone);
        $this->assertSame(['', '0909123456'], collect($order->stops)->pluck('phone')->all());
    }
}
