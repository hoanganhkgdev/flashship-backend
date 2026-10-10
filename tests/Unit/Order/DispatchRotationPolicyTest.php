<?php

namespace Tests\Unit\Order;

use Modules\Core\Models\User;
use Modules\Order\Services\DispatchRotationPolicy;
use PHPUnit\Framework\TestCase;

class DispatchRotationPolicyTest extends TestCase
{
    public function test_higher_composite_score_goes_first_even_if_offered_recently(): void
    {
        $never = $this->driver(1, null, 10);
        $recent = $this->driver(2, now()->timestamp, 100);

        $ranked = DispatchRotationPolicy::sort(collect([$never, $recent]), fn (User $driver) => $driver->_test_score);

        $this->assertSame([2, 1], $ranked->pluck('id')->all());
    }

    public function test_never_offered_driver_wins_a_score_tie(): void
    {
        $recent = $this->driver(1, now()->subMinute()->timestamp, 50);
        $never = $this->driver(2, null, 50);

        $ranked = DispatchRotationPolicy::sort(collect([$recent, $never]), fn (User $driver) => $driver->_test_score);

        $this->assertSame([2, 1], $ranked->pluck('id')->all());
    }

    public function test_oldest_offer_wins_a_score_tie(): void
    {
        $oldest = $this->driver(1, now()->subMinutes(10)->timestamp, 50);
        $newest = $this->driver(2, now()->subMinute()->timestamp, 50);

        $ranked = DispatchRotationPolicy::sort(collect([$newest, $oldest]), fn (User $driver) => $driver->_test_score);

        $this->assertSame([1, 2], $ranked->pluck('id')->all());
    }

    public function test_oldest_offer_wins_inside_fair_score_band(): void
    {
        $recentBest = $this->driver(1, now()->timestamp, 100);
        $oldWithinBand = $this->driver(2, now()->subMinutes(10)->timestamp, 92);

        $ranked = DispatchRotationPolicy::sort(
            collect([$recentBest, $oldWithinBand]),
            fn (User $driver) => $driver->_test_score,
            8,
        );

        $this->assertSame([2, 1], $ranked->pluck('id')->all());
    }

    public function test_better_score_band_stays_ahead_of_old_offer(): void
    {
        $recentBest = $this->driver(1, now()->timestamp, 100);
        $oldOutsideBand = $this->driver(2, now()->subHour()->timestamp, 91.9);

        $ranked = DispatchRotationPolicy::sort(
            collect([$oldOutsideBand, $recentBest]),
            fn (User $driver) => $driver->_test_score,
            8,
        );

        $this->assertSame([1, 2], $ranked->pluck('id')->all());
    }

    private function driver(int $id, ?int $lastOffer, float $score): User
    {
        $driver = new User;
        $driver->id = $id;
        $driver->setAttribute('_last_offered_timestamp', $lastOffer);
        $driver->setAttribute('_test_score', $score);

        return $driver;
    }
}
