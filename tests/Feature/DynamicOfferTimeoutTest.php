<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Http\Controllers\OrderController;
use Modules\Order\Jobs\CheckDriverOfferReceiptJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchExpirySweeper;
use Modules\Order\Services\DispatchService;
use Modules\Order\Services\OrderService;
use Tests\TestCase;

class DynamicOfferTimeoutTest extends TestCase
{
    use DatabaseTransactions;

    public function test_missing_device_receipt_moves_to_next_driver(): void
    {
        [$order, $driver] = $this->activeOffer();
        $dispatch = $this->mock(DispatchService::class);
        $dispatch->shouldReceive('handleTimeout')->once()->withArgs(
            fn (Order $receivedOrder, int $driverId) => $receivedOrder->is($order) && $driverId === $driver->id
        );

        (new CheckDriverOfferReceiptJob($order->id, $driver->id))->handle($dispatch);
    }

    public function test_received_offer_is_not_removed_at_receipt_checkpoint(): void
    {
        [$order, $driver, $log] = $this->activeOffer();
        $log->update(['received_at' => now()]);
        $dispatch = $this->mock(DispatchService::class);
        $dispatch->shouldNotReceive('handleTimeout');

        (new CheckDriverOfferReceiptJob($order->id, $driver->id))->handle($dispatch);
    }

    public function test_auto_offline_check_evaluates_unviewed_window_without_error(): void
    {
        [, $driver] = $this->activeOffer();
        $driver->update(['online_since' => now()->subHour()]);

        $method = new \ReflectionMethod(DispatchService::class, 'autoOfflineAfterUnviewedOffers');
        $method->setAccessible(true);
        $method->invoke(app(DispatchService::class), $driver->id);

        $this->assertTrue((bool) $driver->fresh()->is_online);
    }

    public function test_expiry_sweeper_handles_offer_at_persisted_deadline(): void
    {
        [$order, $driver, $log] = $this->activeOffer();
        $log->update(['expires_at' => now()->subSecond()]);
        $dispatch = $this->mock(DispatchService::class);
        $dispatch->shouldReceive('handleTimeout')->once()->withArgs(
            fn (Order $receivedOrder, int $driverId) => $receivedOrder->is($order) && $driverId === $driver->id
        );

        $handled = (new DispatchExpirySweeper($dispatch))->sweep();

        $this->assertSame(1, $handled);
    }

    public function test_expiry_sweeper_leaves_future_offer_alone(): void
    {
        [, , $log] = $this->activeOffer();
        $log->update(['expires_at' => now()->addMinute()]);
        $dispatch = $this->mock(DispatchService::class);
        $dispatch->shouldNotReceive('handleTimeout');

        $handled = (new DispatchExpirySweeper($dispatch))->sweep();

        $this->assertSame(0, $handled);
    }

    public function test_driver_cannot_accept_an_expired_offer_before_sweeper_runs(): void
    {
        [$order, $driver, $log] = $this->activeOffer();
        $log->update(['expires_at' => now()->subSecond()]);

        $result = app(OrderService::class)->acceptOrder($order, $driver);

        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status']);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_man_id);
    }

    public function test_viewing_offer_persists_one_idempotent_decision_deadline(): void
    {
        [$order, $driver, $log] = $this->activeOffer();
        $log->update(['expires_at' => now()->addSeconds(5)]);
        $method = new \ReflectionMethod(OrderController::class, 'markOfferViewed');
        $method->setAccessible(true);
        $controller = app(OrderController::class);

        $first = $method->invoke($controller, $order->id, $driver->id, $log->id);
        $second = $method->invoke($controller, $order->id, $driver->id, $log->id);

        $this->assertTrue($first['updated']);
        $this->assertFalse($second['updated']);
        $this->assertSame($first['expires_at']->timestamp, $second['expires_at']->timestamp);
        $this->assertNotNull($log->fresh()->viewed_at);
        $this->assertNotNull($log->fresh()->received_at);
        $this->assertSame($first['expires_at']->timestamp, $log->fresh()->expires_at->timestamp);
    }

    private function activeOffer(): array
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $city = City::create(['name' => 'Dynamic timeout', 'slug' => 'dynamic-timeout-'.uniqid(), 'is_active' => true]);
        $driver = User::create([
            'name' => 'Driver', 'email' => uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'driver', 'status' => 1, 'is_online' => true, 'city_id' => $city->id,
        ]);
        $order = Order::create([
            'code' => 'ACK-'.uniqid(), 'status' => 'pending', 'city_id' => $city->id,
            'service_type' => 'delivery', 'platform' => 'customer_app',
            'pickup_address' => 'a', 'delivery_address' => 'b', 'dispatching_to_driver_id' => $driver->id,
        ]);
        $log = OrderDispatchLog::create([
            'order_id' => $order->id, 'driver_id' => $driver->id,
            'offered_at' => now(), 'result' => 'pending',
        ]);

        return [$order, $driver, $log];
    }
}
