<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderMarketService;
use Tests\TestCase;

class OrderMarketOpeningPolicyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_offer_attempt_count_no_longer_opens_market_before_wait_time(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $city = City::create(['name' => 'Market policy', 'slug' => 'market-policy-'.uniqid(), 'is_active' => true]);
        DB::table(OperationalSettings::CITY_TABLE)->updateOrInsert([
            'city_id' => $city->id,
            'key' => 'market.enabled',
        ], [
            'value' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table(OperationalSettings::CITY_TABLE)->updateOrInsert([
            'city_id' => $city->id,
            'key' => 'market.wait_seconds_before_open',
        ], [
            'value' => '180',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        OperationalSettings::flush($city->id);

        $order = Order::create([
            'status' => 'pending', 'city_id' => $city->id, 'service_type' => 'delivery',
            'platform' => 'customer_app', 'pickup_address' => 'a', 'delivery_address' => 'b',
            'dispatch_attempts' => 99, 'dispatch_started_at' => now()->subSeconds(30),
        ]);
        $service = new OrderMarketService($this->mock(DriverLocationService::class));

        $this->assertFalse($service->shouldOpen($order));

        $order->dispatch_started_at = now()->subSeconds(179);
        $this->assertFalse($service->shouldOpen($order));

        $order->dispatch_started_at = now()->subSeconds(181);
        $this->assertTrue($service->shouldOpen($order));
    }
}
