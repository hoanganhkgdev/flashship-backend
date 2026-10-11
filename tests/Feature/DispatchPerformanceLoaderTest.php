<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchPerformanceLoader;
use Tests\TestCase;

class DispatchPerformanceLoaderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_counts_only_actionable_offers_and_recent_visible_ratings(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());

        $city = City::create([
            'name' => 'Performance city',
            'slug' => 'performance-city-'.uniqid(),
            'is_active' => true,
        ]);
        $driver = User::create([
            'name' => 'Performance driver',
            'email' => uniqid().'@performance.test',
            'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => 'driver',
            'status' => 1,
            'city_id' => $city->id,
        ]);

        $this->offer($city, $driver, 'accepted');
        $this->offer($city, $driver, 'declined');
        $this->offer($city, $driver, 'expired', timeout: true);
        $this->offer($city, $driver, 'expired', timeout: false); // Người khác nhận trước.
        $this->offer($city, $driver, 'expired', timeout: true, received: false); // Thiết bị không nhận.

        $this->ratedOrder($city, $driver, 5);
        $this->ratedOrder($city, $driver, 3);
        $this->ratedOrder($city, $driver, 1, hidden: true);
        $this->ratedOrder($city, $driver, 1, completedAt: now()->subDays(100));

        app(DispatchPerformanceLoader::class)->hydrate(collect([$driver]));

        $this->assertSame(1, $driver->getAttribute('_dispatch_accepted_count'));
        $this->assertSame(3, $driver->getAttribute('_dispatch_actionable_count'));
        $this->assertSame(2, $driver->getAttribute('_dispatch_rating_count'));
        $this->assertSame(8.0, $driver->getAttribute('_dispatch_rating_sum'));
    }

    private function offer(
        City $city,
        User $driver,
        string $result,
        bool $timeout = false,
        bool $received = true,
    ): void {
        $order = $this->baseOrder($city);
        $expiresAt = $timeout ? now()->subMinute() : now()->addMinute();

        OrderDispatchLog::create([
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'offered_at' => now()->subMinutes(2),
            'received_at' => $received ? now()->subMinutes(2) : null,
            'expires_at' => $expiresAt,
            'responded_at' => $result === 'expired'
                ? ($timeout ? now() : now()->subSeconds(30))
                : now()->subMinute(),
            'result' => $result,
            'close_reason' => match ($result) {
                'accepted' => OrderDispatchLog::CLOSE_ACCEPTED,
                'declined' => OrderDispatchLog::CLOSE_DRIVER_DECLINED,
                default => $timeout
                    ? ($received ? OrderDispatchLog::CLOSE_DEADLINE_EXPIRED : OrderDispatchLog::CLOSE_DEVICE_UNRECEIVED)
                    : OrderDispatchLog::CLOSE_ANOTHER_DRIVER_ACCEPTED,
            },
        ]);
    }

    private function ratedOrder(
        City $city,
        User $driver,
        int $rating,
        bool $hidden = false,
        mixed $completedAt = null,
    ): void {
        $order = $this->baseOrder($city, [
            'status' => 'completed',
            'delivery_man_id' => $driver->id,
            'driver_rating' => $rating,
            'rating_hidden' => $hidden,
            'completed_at' => $completedAt ?? now()->subDay(),
        ]);
        $order->save();
    }

    private function baseOrder(City $city, array $extra = []): Order
    {
        return Order::create(array_merge([
            'code' => 'PERF-'.uniqid(),
            'city_id' => $city->id,
            'service_type' => 'delivery',
            'status' => 'pending',
            'platform' => 'customer_app',
            'pickup_address' => 'Pickup',
            'pickup_lat' => 10.0,
            'pickup_lng' => 105.0,
            'delivery_address' => 'Delivery',
            'delivery_lat' => 10.01,
            'delivery_lng' => 105.01,
        ], $extra));
    }
}
