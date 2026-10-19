<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderMarketService;
use Modules\Order\Services\OrderService;
use Tests\TestCase;

/** Chợ đơn là đường phát đơn duy nhất: không còn offer hệ thống theo wave. */
class OrderMarketOnlyDispatchTest extends TestCase
{
    use DatabaseTransactions;

    private function pendingOrder(City $city, array $extra = []): Order
    {
        return Order::create($extra + [
            'status' => 'pending', 'city_id' => $city->id, 'service_type' => 'delivery',
            'platform' => 'customer_app', 'pickup_address' => 'a', 'delivery_address' => 'b',
            'pickup_lat' => 10.0, 'pickup_lng' => 105.0,
        ]);
    }

    public function test_new_order_goes_straight_to_the_market_without_any_offer(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $city = City::create(['name' => 'Market only', 'slug' => 'market-only-'.uniqid(), 'is_active' => true]);
        $order = $this->pendingOrder($city);

        app(OrderService::class)->dispatchNewOrder($order->id);

        $this->assertSame('self_pick', $order->fresh()->dispatch_mode);
        $this->assertSame('open', $order->marketListing()->value('status'));
        $this->assertSame(1, DB::table('order_histories')->where('order_id', $order->id)->where('type', 'market_opened')->count());
        $this->assertSame(0, DB::table('order_dispatch_logs')->where('order_id', $order->id)->count());
        $this->assertNull($order->fresh()->dispatching_to_driver_id);
    }

    public function test_cancelling_a_pending_order_closes_its_market_listing(): void
    {
        $city = City::create(['name' => 'Market cancel', 'slug' => 'market-cancel-'.uniqid(), 'is_active' => true]);
        $order = $this->pendingOrder($city);
        app(OrderService::class)->dispatchNewOrder($order->id);

        $result = app(OrderService::class)->cancelPendingOrder($order->fresh(), 'customer');

        $this->assertTrue($result['success']);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $order->marketListing()->value('status'));
    }

    public function test_listing_never_expires_and_stays_visible_until_claimed(): void
    {
        $city = City::create(['name' => 'Market stays', 'slug' => 'market-stays-'.uniqid(), 'is_active' => true]);
        $order = $this->pendingOrder($city);
        $listing = app(OrderMarketService::class)->openForSelfPick($order);
        $listing->update(['opened_at' => now()->subDays(2)]);

        $this->assertNull($listing->fresh()->expires_at);
        $this->assertSame('open', $listing->fresh()->status);
        $this->assertNull($order->fresh()->cancel_reason);
    }

    public function test_deploy_migration_moves_in_flight_orders_into_the_market(): void
    {
        $city = City::create(['name' => 'Market migrate', 'slug' => 'market-migrate-'.uniqid(), 'is_active' => true]);
        $driver = \Modules\Core\Models\User::create([
            'name' => 'Legacy driver', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'city_id' => $city->id,
        ]);
        $inFlight = $this->pendingOrder($city);
        $alreadyStopped = $this->pendingOrder($city);
        DB::table('orders')->where('id', $inFlight->id)->update([
            'dispatch_started_at' => now()->subMinutes(2), 'dispatching_to_driver_id' => $driver->id, 'dispatch_mode' => 'system_assigned',
        ]);
        DB::table('orders')->where('id', $alreadyStopped->id)->update([
            'dispatch_started_at' => now()->subMinutes(20), 'cancel_reason' => 'no_driver',
        ]);
        DB::table('order_dispatch_logs')->insert([
            'order_id' => $inFlight->id, 'driver_id' => $driver->id, 'offered_at' => now(),
            'expires_at' => now()->addSeconds(15), 'result' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        (require base_path('Modules/Order/database/migrations/2026_10_18_000001_move_pending_dispatch_orders_to_order_market.php'))->up();

        $this->assertSame('expired', DB::table('order_dispatch_logs')->where('order_id', $inFlight->id)->value('result'));
        $this->assertSame('open', $inFlight->marketListing()->value('status'));
        $this->assertSame('self_pick', $inFlight->fresh()->dispatch_mode);
        $this->assertNull($inFlight->fresh()->dispatching_to_driver_id);
        $this->assertNull($alreadyStopped->marketListing()->first(), 'đơn đã dừng tìm vẫn chờ tổng đài xử lý');
    }
}
