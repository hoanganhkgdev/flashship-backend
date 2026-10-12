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

class ShopOrderReceiverPhoneTest extends TestCase
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

    public function test_delivery_order_requires_the_receiver_phone(): void
    {
        Sanctum::actingAs($this->shop());

        $this->postJson('/api/shop/orders', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_phone'])
            ->assertJsonPath('errors.delivery_phone.0', 'Vui lòng nhập SĐT người nhận.');
    }

    public function test_receiver_phone_is_saved_when_provided(): void
    {
        Sanctum::actingAs($this->shop());

        $this->postJson('/api/shop/orders', $this->payload(['delivery_phone' => '0909123456']))->assertStatus(201);

        $this->assertSame('0909123456', Order::latest('id')->first()->delivery_phone);
    }

    public function test_batch_order_requires_a_phone_on_every_stop(): void
    {
        Sanctum::actingAs($this->shop());
        $base = ['pickup_address' => 'Cửa hàng', 'pickup_lat' => 10.0, 'pickup_lng' => 105.0, 'cargo_type' => 'food'];

        $response = $this->postJson('/api/shop/orders/batch', $base + ['stops' => [
            ['address' => 'Điểm 1', 'lat' => 10.01, 'lng' => 105.01, 'phone' => '0909123456'],
            ['address' => 'Điểm 2', 'lat' => 10.02, 'lng' => 105.02],
        ]])->assertStatus(422)->assertJsonValidationErrors(['stops.1.phone']);

        $this->assertSame('Điểm giao 2 cần nhập SĐT người nhận.', $response->json('errors')['stops.1.phone'][0]);
    }

    public function test_batch_order_is_created_when_every_stop_has_a_phone(): void
    {
        Sanctum::actingAs($this->shop());

        $this->postJson('/api/shop/orders/batch', [
            'pickup_address' => 'Cửa hàng', 'pickup_lat' => 10.0, 'pickup_lng' => 105.0, 'cargo_type' => 'food',
            'stops' => [
                ['address' => 'Điểm 1', 'lat' => 10.01, 'lng' => 105.01, 'phone' => '0909123456'],
                ['address' => 'Điểm 2', 'lat' => 10.02, 'lng' => 105.02, 'phone' => '0909654321'],
            ],
        ])->assertStatus(201);

        $order = Order::latest('id')->first();
        $this->assertSame('0909123456', $order->delivery_phone);
        $this->assertSame(['0909123456', '0909654321'], collect($order->stops)->pluck('phone')->all());
    }
}
