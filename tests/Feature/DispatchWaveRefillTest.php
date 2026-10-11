<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Core\Services\OperationalSettings;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchCandidateFinder;
use Modules\Order\Services\DispatchManualAssignment;
use Modules\Order\Services\DispatchOfferSender;
use Modules\Order\Services\DispatchScoringCalculator;
use Modules\Order\Services\DispatchService;
use Modules\Order\Services\OrderMarketService;
use Tests\TestCase;

class DispatchWaveRefillTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_only_fills_the_open_slots_in_an_existing_wave(): void
    {
        [$city, $order] = $this->order();
        OperationalSettings::put(['dispatch.wave_size' => 4], $city->id);

        $alreadyOffered = collect([1, 2])->map(fn ($number) => $this->driver($city, "Existing {$number}"));
        foreach ($alreadyOffered as $driver) {
            OrderDispatchLog::create([
                'order_id' => $order->id,
                'driver_id' => $driver->id,
                'offered_at' => now(),
                'expires_at' => now()->addSeconds(15),
                'result' => 'pending',
            ]);
        }
        $candidates = collect([1, 2, 3])->map(fn ($number) => $this->driver($city, "Candidate {$number}"));

        $finder = Mockery::mock(DispatchCandidateFinder::class);
        $finder->shouldReceive('find')->once()->withArgs(function (Order $foundOrder, array $excluded) use ($order, $alreadyOffered) {
            sort($excluded);
            $expected = $alreadyOffered->pluck('id')->all();
            sort($expected);

            return $foundOrder->is($order) && $excluded === $expected;
        })->andReturn($candidates);

        $sender = Mockery::mock(DispatchOfferSender::class);
        $sender->shouldReceive('send')->twice()->andReturn(true);

        $market = Mockery::mock(OrderMarketService::class);
        $market->shouldReceive('isOpen')->once()->andReturn(false);

        Redis::shouldReceive('set')->once()->andReturn(true);
        Redis::shouldReceive('eval')->once()->andReturn(1);

        $this->service($finder, $sender, $market)->offerToNext($order);
    }

    public function test_full_wave_does_not_search_or_send_more_offers(): void
    {
        [$city, $order] = $this->order();
        OperationalSettings::put(['dispatch.wave_size' => 4], $city->id);

        foreach (range(1, 4) as $number) {
            $driver = $this->driver($city, "Full {$number}");
            OrderDispatchLog::create([
                'order_id' => $order->id,
                'driver_id' => $driver->id,
                'offered_at' => now(),
                'expires_at' => now()->addSeconds(15),
                'result' => 'pending',
            ]);
        }

        $finder = Mockery::mock(DispatchCandidateFinder::class);
        $finder->shouldNotReceive('find');
        $sender = Mockery::mock(DispatchOfferSender::class);
        $sender->shouldNotReceive('send');
        $market = Mockery::mock(OrderMarketService::class);
        $market->shouldReceive('isOpen')->once()->andReturn(false);

        Redis::shouldReceive('set')->once()->andReturn(true);
        Redis::shouldReceive('eval')->once()->andReturn(1);

        $this->service($finder, $sender, $market)->offerToNext($order);
    }

    public function test_it_does_not_open_a_new_wave_after_the_total_deadline(): void
    {
        [$city, $order] = $this->order();
        DB::table('orders')->where('id', $order->id)->update([
            'dispatch_started_at' => now()->subMinutes(
                OperationalSettings::dispatchTimeoutMinutes($city->id) + 1
            ),
        ]);

        $finder = Mockery::mock(DispatchCandidateFinder::class);
        $finder->shouldNotReceive('find');
        $sender = Mockery::mock(DispatchOfferSender::class);
        $sender->shouldNotReceive('send');
        $market = Mockery::mock(OrderMarketService::class);
        $market->shouldReceive('isOpen')->once()->andReturn(false);

        Redis::shouldReceive('set')->once()->andReturn(true);
        Redis::shouldReceive('del')->once()->andReturn(1);
        Redis::shouldReceive('eval')->once()->andReturn(1);

        $this->service($finder, $sender, $market)->offerToNext($order->fresh());

        $this->assertSame('no_driver', $order->fresh()->cancel_reason);
        $this->assertSame(0, OrderDispatchLog::where('order_id', $order->id)->count());
    }

    private function service(
        DispatchCandidateFinder $finder,
        DispatchOfferSender $sender,
        OrderMarketService $market,
    ): DispatchService {
        return new DispatchService(
            Mockery::mock(DispatchScoringCalculator::class),
            $finder,
            $sender,
            Mockery::mock(DispatchManualAssignment::class),
            $market,
        );
    }

    private function order(): array
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $city = City::create([
            'name' => 'Wave refill',
            'slug' => 'wave-refill-'.uniqid(),
            'is_active' => true,
        ]);
        $order = Order::create([
            'code' => 'REFILL-'.uniqid(),
            'status' => 'pending',
            'dispatch_mode' => 'system_assigned',
            'dispatch_started_at' => now(),
            'city_id' => $city->id,
            'service_type' => 'delivery',
            'platform' => 'customer_app',
            'pickup_address' => 'Pickup',
            'pickup_lat' => 10.0,
            'pickup_lng' => 105.0,
            'delivery_address' => 'Delivery',
            'delivery_lat' => 10.01,
            'delivery_lng' => 105.01,
        ]);

        return [$city, $order];
    }

    private function driver(City $city, string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => uniqid().'@wave-refill.test',
            'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => 'driver',
            'status' => 1,
            'is_online' => true,
            'city_id' => $city->id,
        ]);
    }
}
