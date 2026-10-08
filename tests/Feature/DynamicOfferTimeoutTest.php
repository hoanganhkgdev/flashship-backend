<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Jobs\CheckDriverOfferReceiptJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchService;
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
