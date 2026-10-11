<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Core\Services\OperationalSettings;
use Modules\Order\Services\DispatchScoringCalculator;
use Tests\TestCase;

class DispatchScoringCalculatorTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private DispatchScoringCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());

        $this->city = City::create([
            'name' => 'Scoring city',
            'slug' => 'scoring-city-'.uniqid(),
            'is_active' => true,
        ]);
        OperationalSettings::put([
            'driver_score.max_score' => 100,
            'dispatch.score_weight' => 10,
            'dispatch.wait_weight' => 10,
            'dispatch.distance_weight' => 10,
            'dispatch.wait_cap_minutes' => 60,
        ], $this->city->id);
        $this->calculator = app(DispatchScoringCalculator::class);
    }

    public function test_weights_are_relative_even_when_they_do_not_total_one_hundred(): void
    {
        $driver = $this->driver(score: 100, onlineSince: now()->subMinutes(30));

        // Chưa có lịch sử dùng điểm nền: 80% phản hồi, 4.5 sao và điểm vận hành tối đa.
        $this->assertEqualsWithDelta(29.0, $this->calculator->scoreComponent($driver, $this->city->id), 0.01);
        $this->assertEqualsWithDelta(16.667, $this->calculator->waitTimeScore($driver, $this->city->id), 0.01);
        $this->assertEqualsWithDelta(16.667, $this->calculator->distanceComponent(2, 4, $this->city->id), 0.01);
    }

    public function test_wait_starts_at_the_later_of_online_and_last_completion(): void
    {
        $restarted = $this->driver(score: 100, onlineSince: now()->subMinutes(10));
        $restarted->last_order_completed_at = now()->subDay();

        $completedRecently = $this->driver(score: 100, onlineSince: now()->subHour());
        $completedRecently->last_order_completed_at = now()->subMinutes(5);

        $this->assertEqualsWithDelta(5.556, $this->calculator->waitTimeScore($restarted, $this->city->id), 0.1);
        $this->assertEqualsWithDelta(2.778, $this->calculator->waitTimeScore($completedRecently, $this->city->id), 0.1);
    }

    public function test_future_wait_timestamp_gets_no_wait_score(): void
    {
        $driver = $this->driver(score: 100, onlineSince: now()->addMinutes(5));

        $this->assertSame(0.0, $this->calculator->waitTimeScore($driver, $this->city->id));
    }

    public function test_driver_score_and_distance_are_clamped_to_valid_range(): void
    {
        $driver = $this->driver(score: 150, onlineSince: now());

        $this->assertEqualsWithDelta(29.0, $this->calculator->scoreComponent($driver, $this->city->id), 0.01);
        $this->assertSame(0.0, $this->calculator->distanceComponent(10, 4, $this->city->id));
        $this->assertEqualsWithDelta(33.333, $this->calculator->distanceComponent(-1, 4, $this->city->id), 0.01);
    }

    public function test_recent_service_performance_uses_smoothed_offer_and_rating_history(): void
    {
        $newDriver = $this->driver(score: 100, onlineSince: now());

        $strong = $this->driver(score: 100, onlineSince: now());
        $strong->setAttribute('_dispatch_accepted_count', 20);
        $strong->setAttribute('_dispatch_actionable_count', 20);
        $strong->setAttribute('_dispatch_rating_count', 10);
        $strong->setAttribute('_dispatch_rating_sum', 50);

        $poor = $this->driver(score: 0, onlineSince: now());
        $poor->setAttribute('_dispatch_accepted_count', 0);
        $poor->setAttribute('_dispatch_actionable_count', 20);
        $poor->setAttribute('_dispatch_rating_count', 10);
        $poor->setAttribute('_dispatch_rating_sum', 20);

        $this->assertEqualsWithDelta(0.87, $this->calculator->servicePerformance($newDriver, $this->city->id), 0.001);
        // Điểm nền vẫn làm mượt 20 mẫu tốt, tránh nhảy thẳng lên tuyệt đối.
        $this->assertEqualsWithDelta(0.97, $this->calculator->servicePerformance($strong, $this->city->id), 0.001);
        $this->assertEqualsWithDelta(0.25, $this->calculator->servicePerformance($poor, $this->city->id), 0.001);
    }

    private function driver(int $score, mixed $onlineSince): User
    {
        $driver = new User;
        $driver->driver_score = $score;
        $driver->online_since = $onlineSince;

        return $driver;
    }
}
