<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Jobs\DispatchOrderRetryJob;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchRecoverySweeper;
use Modules\Order\Services\DispatchService;
use Tests\TestCase;

class DispatchRecoverySweeperTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_recovers_only_started_system_orders_without_an_open_offer(): void
    {
        $city = $this->city();
        $driver = $this->driver($city);
        $orphan = $this->order($city);
        $withOffer = $this->order($city);
        $stopped = $this->order($city, ['cancel_reason' => 'no_driver']);
        $selfPick = $this->order($city, ['dispatch_mode' => 'self_pick']);

        OrderDispatchLog::create([
            'order_id' => $withOffer->id,
            'driver_id' => $driver->id,
            'offered_at' => now(),
            'expires_at' => now()->addSeconds(15),
            'result' => 'pending',
        ]);

        $dispatch = Mockery::mock(DispatchService::class);
        $dispatch->shouldReceive('offerToNext')->once()->withArgs(
            fn (Order $order) => $order->is($orphan)
        );
        Redis::shouldReceive('exists')->once()
            ->with("dispatch:retry_pending:{$orphan->id}")
            ->andReturn(0);

        $handled = (new DispatchRecoverySweeper($dispatch))->sweep();

        $this->assertSame(1, $handled);
        $this->assertNotSame($orphan->id, $withOffer->id);
        $this->assertNotSame($orphan->id, $stopped->id);
        $this->assertNotSame($orphan->id, $selfPick->id);
    }

    public function test_it_respects_a_retry_that_is_already_scheduled(): void
    {
        $city = $this->city();
        $order = $this->order($city);

        Redis::shouldReceive('exists')->once()
            ->with("dispatch:retry_pending:{$order->id}")
            ->andReturn(1);
        $dispatch = Mockery::mock(DispatchService::class);
        $dispatch->shouldNotReceive('offerToNext');

        $this->assertSame(0, (new DispatchRecoverySweeper($dispatch))->sweep());
    }

    public function test_retry_delegates_timeout_decision_even_when_total_time_has_elapsed(): void
    {
        $city = $this->city();
        $order = $this->order($city);

        Redis::shouldReceive('del')->once()->with("dispatch:retry_pending:{$order->id}")->andReturn(1);
        $dispatch = Mockery::mock(DispatchService::class);
        $dispatch->shouldReceive('offerToNext')->once()->withArgs(
            fn (Order $received) => $received->is($order)
        );
        $dispatch->shouldNotReceive('markNoDriver');

        (new DispatchOrderRetryJob($order->id))->handle($dispatch);
    }

    private function city(): City
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());

        return City::create([
            'name' => 'Recovery city',
            'slug' => 'recovery-city-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function driver(City $city): User
    {
        return User::create([
            'name' => 'Recovery driver',
            'email' => uniqid().'@recovery.test',
            'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => 'driver',
            'status' => 1,
            'is_online' => true,
            'city_id' => $city->id,
        ]);
    }

    private function order(City $city, array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'code' => 'RECOVERY-'.uniqid(),
            'status' => 'pending',
            'dispatch_mode' => 'system_assigned',
            'city_id' => $city->id,
            'service_type' => 'delivery',
            'platform' => 'customer_app',
            'pickup_address' => 'Pickup',
            'pickup_lat' => 10.0,
            'pickup_lng' => 105.0,
            'delivery_address' => 'Delivery',
            'delivery_lat' => 10.01,
            'delivery_lng' => 105.01,
        ], $extra));

        DB::table('orders')->where('id', $order->id)->update([
            'dispatch_started_at' => now()->subMinutes(20),
        ]);

        return $order->fresh();
    }
}
