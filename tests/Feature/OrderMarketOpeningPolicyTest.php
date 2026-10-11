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

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('dispatch.self_pick_enabled', true);
    }

    public function test_order_keeps_dispatch_mode_snapshot_when_city_setting_changes(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $city = City::create(['name' => 'Market policy', 'slug' => 'market-policy-'.uniqid(), 'is_active' => true]);
        DB::table(OperationalSettings::CITY_TABLE)->updateOrInsert([
            'city_id' => $city->id,
            'key' => 'dispatch.mode',
        ], [
            'value' => 'system_assigned',
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

        DB::table(OperationalSettings::CITY_TABLE)->where('city_id', $city->id)
            ->where('key', 'dispatch.mode')->update(['value' => 'self_pick', 'updated_at' => now()]);
        OperationalSettings::flush($city->id);

        $this->assertFalse($service->shouldOpen($order->fresh()));

        $newOrder = Order::create([
            'status' => 'pending', 'city_id' => $city->id, 'service_type' => 'delivery',
            'platform' => 'customer_app', 'pickup_address' => 'a', 'delivery_address' => 'b',
        ]);
        $this->assertSame('system_assigned', $order->fresh()->effectiveDispatchMode());
        $this->assertSame('self_pick', $newOrder->effectiveDispatchMode());
        $this->assertTrue($service->shouldOpen($newOrder));

        DB::table('orders')->where('id', $order->id)->update(['dispatch_mode' => null]);
        $this->assertSame('system_assigned', $order->fresh()->effectiveDispatchMode());
    }
}
