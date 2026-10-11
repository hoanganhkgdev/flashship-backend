<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderMarketListing;
use Modules\Order\Jobs\RouteNewOrderJob;
use Modules\Order\Services\DispatchCandidateFinder;
use Modules\Order\Services\DispatchManualAssignment;
use Modules\Order\Services\DispatchOfferSender;
use Modules\Order\Services\DispatchScoringCalculator;
use Modules\Order\Services\DispatchService;
use Modules\Order\Services\OrderMarketService;
use Modules\Order\Services\OrderMarketExpirySweeper;
use Tests\TestCase;

class DispatchModeRoutingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('dispatch.self_pick_enabled', true);
    }

    public function test_route_new_order_job_delegates_to_idempotent_router(): void
    {
        $orders = $this->mock(\Modules\Order\Services\OrderService::class);
        $orders->shouldReceive('dispatchNewOrder')->once()->with(123);

        (new RouteNewOrderJob(123))->handle($orders);
    }

    public function test_self_pick_opens_listing_immediately(): void
    {
        Queue::fake();
        Redis::shouldReceive('del')->once();

        $city = City::create([
            'name' => 'Immediate market',
            'slug' => 'immediate-market-'.uniqid(),
            'is_active' => true,
        ]);
        OperationalSettings::put(['dispatch.mode' => 'self_pick'], $city->id);
        $order = Order::create([
            'code' => 'IMMEDIATE-'.uniqid(),
            'status' => 'pending',
            'city_id' => $city->id,
            'service_type' => 'delivery',
            'platform' => 'customer_app',
            'pickup_address' => 'Điểm lấy',
            'delivery_address' => 'Điểm giao',
            'pickup_lat' => 10.0,
            'pickup_lng' => 105.0,
        ]);

        $locations = $this->mock(DriverLocationService::class);
        $locations->shouldReceive('freshLocationsFor')->once()->andReturn([]);
        $listing = (new OrderMarketService($locations))->openForSelfPick($order);

        $this->assertNotNull($listing);
        $this->assertSame('open', $listing->status);
        $this->assertSame('self_pick', $listing->open_reason);
        $this->assertNotNull($order->fresh()->dispatch_started_at);
    }

    public function test_self_pick_city_opens_market_without_sending_system_offer(): void
    {
        $city = City::create([
            'name' => 'Self pick city',
            'slug' => 'self-pick-'.uniqid(),
            'is_active' => true,
        ]);
        OperationalSettings::put(['dispatch.mode' => 'self_pick'], $city->id);

        $order = Order::create([
            'code' => 'SELF-'.uniqid(),
            'status' => 'pending',
            'city_id' => $city->id,
            'service_type' => 'delivery',
            'platform' => 'customer_app',
            'pickup_address' => 'Điểm lấy',
            'delivery_address' => 'Điểm giao',
            'pickup_lat' => 10.0,
            'pickup_lng' => 105.0,
        ]);

        $market = $this->mock(OrderMarketService::class);
        $market->shouldReceive('openForSelfPick')->once()->withArgs(
            fn (Order $routedOrder) => $routedOrder->is($order)
        );
        $candidates = $this->mock(DispatchCandidateFinder::class);
        $candidates->shouldNotReceive('find');
        $offers = $this->mock(DispatchOfferSender::class);
        $offers->shouldNotReceive('send');

        $service = new DispatchService(
            $this->mock(DispatchScoringCalculator::class),
            $candidates,
            $offers,
            $this->mock(DispatchManualAssignment::class),
            $market,
        );

        $service->startDispatch($order);
    }

    public function test_expired_self_pick_listing_keeps_order_pending_and_notifies_customer_support_is_handling_it(): void
    {
        Redis::shouldReceive('del')->once()->andReturn(1);

        $city = City::create([
            'name' => 'Expired self pick',
            'slug' => 'expired-self-pick-'.uniqid(),
            'is_active' => true,
        ]);
        OperationalSettings::put([
            'dispatch.mode' => 'self_pick',
            'market.expire_minutes' => 7,
        ], $city->id);
        $customer = User::create([
            'name' => 'Khách chờ tài xế',
            'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => 'customer',
            'status' => 1,
            'city_id' => $city->id,
        ]);
        $order = Order::create([
            'code' => 'EXPIRED-'.uniqid(),
            'status' => 'pending',
            'city_id' => $city->id,
            'sender_platform_id' => $customer->id,
            'service_type' => 'delivery',
            'platform' => 'customer_app',
            'pickup_address' => 'Điểm lấy',
            'delivery_address' => 'Điểm giao',
            'pickup_lat' => 10.0,
            'pickup_lng' => 105.0,
        ]);
        $listing = OrderMarketListing::create([
            'order_id' => $order->id,
            'city_id' => $city->id,
            'status' => 'open',
            'open_reason' => 'self_pick',
            'opened_at' => now()->subMinutes(8),
            'expires_at' => now()->subMinute(),
        ]);

        $market = new OrderMarketService($this->mock(DriverLocationService::class));
        $market->expire($listing->id);

        $this->assertSame('expired', $listing->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('no_driver', $order->fresh()->cancel_reason);
        $history = DB::table('order_histories')->where('order_id', $order->id)->where('type', 'no_driver')->first();
        $this->assertNotNull($history);
        $this->assertStringContainsString('7 phút', $history->description);
        $this->assertSame('self_pick', json_decode($history->metadata, true)['source']);
        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => $customer->id,
            'type' => 'order_status',
            'order_code' => $order->code,
        ]);
    }

    public function test_market_expiry_sweeper_recovers_due_listing_when_delayed_job_is_missed(): void
    {
        $city = City::create(['name' => 'Sweep city', 'slug' => 'sweep-city-'.uniqid(), 'is_active' => true]);
        $due = $this->listing($city, now()->subMinute());
        $this->listing($city, now()->addMinute());
        $market = $this->mock(OrderMarketService::class);
        $market->shouldReceive('expire')->once()->with($due->id);

        $handled = (new OrderMarketExpirySweeper($market))->sweep();

        $this->assertSame(1, $handled);
    }

    private function pendingOrder(City $city): Order
    {
        return Order::create([
            'code' => 'PENDING-'.uniqid(), 'status' => 'pending', 'city_id' => $city->id,
            'service_type' => 'delivery', 'platform' => 'customer_app',
            'pickup_address' => 'Điểm lấy', 'delivery_address' => 'Điểm giao',
            'pickup_lat' => 10.0, 'pickup_lng' => 105.0,
        ]);
    }

    private function listing(City $city, \DateTimeInterface $expiresAt): OrderMarketListing
    {
        $order = $this->pendingOrder($city);

        return OrderMarketListing::create([
            'order_id' => $order->id, 'city_id' => $city->id, 'status' => 'open',
            'open_reason' => 'self_pick', 'opened_at' => now()->subMinutes(2), 'expires_at' => $expiresAt,
        ]);
    }
}
