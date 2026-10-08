<?php

namespace Tests\Unit\Order;

use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Order\Services\DispatchRotationPolicy;
use PHPUnit\Framework\TestCase;

class DispatchRotationPolicyTest extends TestCase
{
    public function test_never_offered_driver_goes_before_high_score_recent_driver(): void
    {
        $never = $this->driver(1, null, 10);
        $recent = $this->driver(2, now()->timestamp, 100);

        $ranked = DispatchRotationPolicy::sort(collect([$recent, $never]), fn (User $driver) => $driver->_test_score);

        $this->assertSame([1, 2], $ranked->pluck('id')->all());
    }

    public function test_oldest_offer_gets_next_turn(): void
    {
        $oldest = $this->driver(1, now()->subMinutes(10)->timestamp, 10);
        $newest = $this->driver(2, now()->subMinute()->timestamp, 100);

        $ranked = DispatchRotationPolicy::sort(collect([$newest, $oldest]), fn (User $driver) => $driver->_test_score);

        $this->assertSame([1, 2], $ranked->pluck('id')->all());
    }

    public function test_composite_score_breaks_equal_rotation_turn(): void
    {
        $low = $this->driver(1, 1000, 20);
        $high = $this->driver(2, 1000, 80);

        $ranked = DispatchRotationPolicy::sort(new Collection([$low, $high]), fn (User $driver) => $driver->_test_score);

        $this->assertSame([2, 1], $ranked->pluck('id')->all());
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
