<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverLicense;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchCandidateFinder;
use Tests\TestCase;

class DispatchCandidateFinderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_filters_ineligible_drivers_before_distance_lookup_and_prioritizes_fresh_gps(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());

        $city = City::create([
            'name' => 'Candidate city',
            'slug' => 'candidate-city-'.uniqid(),
            'is_active' => true,
        ]);
        $order = $this->order($city, 'car');

        $fresh = $this->driver($city, 10);
        $grace = $this->driver($city, 140);
        $far = $this->driver($city, 100);
        $overdue = $this->driver($city, 100);
        $busy = $this->driver($city, 100);
        $offeredElsewhere = $this->driver($city, 100);
        $onLeave = $this->driver($city, 100);
        $unlicensed = $this->driver($city, 100, false);

        DB::table('driver_debts')->insert([
            'driver_id' => $overdue->id,
            'debt_type' => 'weekly',
            'status' => 'overdue',
            'amount_due' => 100000,
            'amount_paid' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->order($city, 'delivery', 'assigned', $busy->id);
        $otherOfferOrder = $this->order($city);
        OrderDispatchLog::create([
            'order_id' => $otherOfferOrder->id,
            'driver_id' => $offeredElsewhere->id,
            'offered_at' => now(),
            'expires_at' => now()->addMinute(),
            'result' => 'pending',
        ]);
        DB::table('driver_leave_requests')->insert([
            'driver_id' => $onLeave->id,
            'leave_date' => today(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mock(DriverLocationService::class, function ($mock) use ($fresh, $grace, $far) {
            $mock->shouldReceive('freshLocationsFor')->once()->withArgs(function (array $ids) use ($fresh, $grace, $far) {
                sort($ids);
                $expected = [$fresh->id, $grace->id, $far->id];
                sort($expected);

                return $ids === $expected;
            })->andReturn([
                $fresh->id => ['lat' => 10.001, 'lng' => 105.001, 'bearing' => null, 'age_seconds' => 20],
                $grace->id => ['lat' => 10.002, 'lng' => 105.002, 'bearing' => null, 'age_seconds' => 120],
                // Bị loại bằng haversine trước khi gọi Google.
                $far->id => ['lat' => 10.1, 'lng' => 105.1, 'bearing' => null, 'age_seconds' => 20],
            ]);
        });
        Http::fake(['*' => Http::response([
            'status' => 'OK',
            'routes' => [['legs' => [['distance' => ['value' => 1000]]]]],
        ])]);

        $result = app(DispatchCandidateFinder::class)->find($order, [], 4);

        // Grace có điểm cao hơn nhưng GPS cũ 120 giây nên vẫn đứng sau Fresh.
        $this->assertSame([$fresh->id, $grace->id], $result->pluck('id')->all());
        Http::assertSentCount(2);
    }

    private function driver(City $city, int $score, bool $approvedLicense = true): User
    {
        $driver = User::create([
            'name' => 'Candidate '.uniqid(),
            'email' => uniqid().'@candidate.test',
            'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password',
            'user_type' => 'driver',
            'status' => 1,
            'is_online' => true,
            'online_since' => now()->subHour(),
            'driver_score' => $score,
            'city_id' => $city->id,
        ]);

        if ($approvedLicense) {
            DriverLicense::create([
                'user_id' => $driver->id,
                'image_path' => 'test-license.jpg',
                'status' => DriverLicense::STATUS_APPROVED,
            ]);
        }

        return $driver;
    }

    private function order(
        City $city,
        string $service = 'delivery',
        string $status = 'pending',
        ?int $driverId = null,
    ): Order {
        return Order::create([
            'code' => 'CAND-'.uniqid(),
            'city_id' => $city->id,
            'service_type' => $service,
            'status' => $status,
            'delivery_man_id' => $driverId,
            'platform' => 'customer_app',
            'pickup_address' => 'Pickup',
            'pickup_lat' => 10.0,
            'pickup_lng' => 105.0,
            'delivery_address' => 'Delivery',
            'delivery_lat' => 10.01,
            'delivery_lng' => 105.01,
        ]);
    }
}
