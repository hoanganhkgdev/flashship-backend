<?php

namespace Tests\Unit\Order;

use Modules\Core\Models\User;
use Modules\Order\Services\DispatchGpsFreshnessPolicy;
use PHPUnit\Framework\TestCase;

class DispatchGpsFreshnessPolicyTest extends TestCase
{
    public function test_fresh_gps_drivers_stay_ahead_of_grace_period_drivers(): void
    {
        $graceBest = $this->driver(1, 120);
        $freshSecond = $this->driver(2, 30);
        $freshThird = $this->driver(3, 90);
        $graceLast = $this->driver(4, 180);

        $ranked = DispatchGpsFreshnessPolicy::prioritize(
            collect([$graceBest, $freshSecond, $freshThird, $graceLast]),
        );

        $this->assertSame([2, 3, 1, 4], $ranked->pluck('id')->all());
    }

    public function test_missing_age_is_backward_compatible_and_treated_as_fresh(): void
    {
        $driver = new User;
        $driver->id = 9;

        $this->assertSame([9], DispatchGpsFreshnessPolicy::prioritize(collect([$driver]))->pluck('id')->all());
    }

    private function driver(int $id, int $ageSeconds): User
    {
        $driver = new User;
        $driver->id = $id;
        $driver->setAttribute('_gps_age_seconds', $ageSeconds);

        return $driver;
    }
}
