<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\OrderService;
use Modules\Order\Services\OrderTimeline;
use Modules\Order\Services\UnviewedOfferWindowPolicy;
use Tests\TestCase;

class DispatchWaveAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_first_driver_wins_and_other_offer_is_closed_atomically(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        Redis::shouldReceive('eval')->twice()->andReturn(1);

        $city = City::create(['name' => 'Wave city', 'slug' => 'wave-city-'.uniqid(), 'is_active' => true]);
        $drivers = collect([1, 2])->map(fn ($number) => User::create([
            'name' => "Wave driver {$number}", 'email' => uniqid()."{$number}@test.local",
            'phone' => '09'.random_int(10000000, 99999999), 'password' => 'test-password',
            'user_type' => 'driver', 'status' => 1, 'is_online' => true, 'city_id' => $city->id,
        ]));
        $order = Order::create([
            'code' => 'WAVE-'.uniqid(), 'status' => 'pending', 'city_id' => $city->id,
            'service_type' => 'delivery', 'platform' => 'customer_app',
            'pickup_address' => 'a', 'delivery_address' => 'b',
            'dispatching_to_driver_id' => $drivers[0]->id,
        ]);
        foreach ($drivers as $driver) {
            OrderDispatchLog::create([
                'order_id' => $order->id, 'driver_id' => $driver->id, 'offered_at' => now(),
                'expires_at' => now()->addSeconds(10), 'result' => 'pending',
            ]);
        }

        $winner = app(OrderService::class)->acceptOrder($order, $drivers[0]);
        $late = app(OrderService::class)->acceptOrder($order->fresh(), $drivers[1]);

        $this->assertTrue($winner['success']);
        $this->assertFalse($late['success']);
        $this->assertSame(409, $late['status']);
        $this->assertSame($drivers[0]->id, $order->fresh()->delivery_man_id);
        $this->assertSame('accepted', OrderDispatchLog::where('driver_id', $drivers[0]->id)->value('result'));
        $this->assertSame(OrderDispatchLog::CLOSE_ACCEPTED, OrderDispatchLog::where('driver_id', $drivers[0]->id)->value('close_reason'));
        $loserOffer = OrderDispatchLog::where('driver_id', $drivers[1]->id)->first();
        $this->assertSame('expired', $loserOffer->result);
        $this->assertSame(OrderDispatchLog::CLOSE_ANOTHER_DRIVER_ACCEPTED, $loserOffer->close_reason);
        $this->assertTrue($loserOffer->responded_at->lt($loserOffer->expires_at));

        $window = UnviewedOfferWindowPolicy::evaluate([$loserOffer], $city->id);
        $this->assertSame(0, $window['total']);
        $this->assertSame(0, $window['unviewed']);
        $this->assertFalse($window['should_offline']);
        $this->assertTrue(OrderTimeline::for($order->fresh())->pluck('text')->contains(
            fn (string $text) => str_contains($text, 'được thu hồi vì tài xế khác đã nhận đơn')
        ));
    }

    public function test_driver_who_became_offline_cannot_accept_a_stale_offer(): void
    {
        Redis::shouldReceive('del')->once()->andReturn(1);
        $city = City::create(['name' => 'Offline accept', 'slug' => 'offline-accept-'.uniqid(), 'is_active' => true]);
        $driver = User::create([
            'name' => 'Offline driver', 'email' => uniqid().'@test.local',
            'phone' => '09'.random_int(10000000, 99999999), 'password' => 'test-password',
            'user_type' => 'driver', 'status' => 1, 'is_online' => true, 'city_id' => $city->id,
        ]);
        $order = Order::create([
            'code' => 'OFFLINE-'.uniqid(), 'status' => 'pending', 'city_id' => $city->id,
            'service_type' => 'delivery', 'platform' => 'customer_app',
            'pickup_address' => 'a', 'delivery_address' => 'b',
            'dispatching_to_driver_id' => $driver->id,
        ]);
        $offer = OrderDispatchLog::create([
            'order_id' => $order->id, 'driver_id' => $driver->id, 'offered_at' => now(),
            'expires_at' => now()->addSeconds(10), 'result' => 'pending',
        ]);
        $driver->update(['is_online' => false]);

        $result = app(OrderService::class)->acceptOrder($order, $driver);

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status']);
        $this->assertSame('expired', $offer->fresh()->result);
        $this->assertSame(OrderDispatchLog::CLOSE_DRIVER_INELIGIBLE, $offer->fresh()->close_reason);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivery_man_id);
    }
}
