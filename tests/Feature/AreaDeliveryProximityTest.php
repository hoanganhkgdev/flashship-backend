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
use Modules\Shop\Models\ShopDestinationPrice;
use Tests\TestCase;

class AreaDeliveryProximityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
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
            'cargo_type' => 'food', 'delivery_phone' => '0909000006',
            'destination_id' => ShopDestinationPrice::where('city_id', $city->id)->value('id'),
        ])->assertStatus(201);

        $this->assertNull(Order::latest('id')->first()->delivery_area_radius_km);
    }
}
