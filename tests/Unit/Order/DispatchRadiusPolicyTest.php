<?php

namespace Tests\Unit\Order;

use Modules\Order\Services\DispatchRadiusPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DispatchRadiusPolicyTest extends TestCase
{
    #[DataProvider('radiusCases')]
    public function test_radius_expands_by_elapsed_time(int $seconds, float $expected): void
    {
        $this->assertSame($expected, DispatchRadiusPolicy::radiusForElapsedSeconds($seconds));
    }

    public static function radiusCases(): array
    {
        return [
            'start'       => [0, 1.0],
            '59 seconds'  => [59, 1.0],
            '1 minute'    => [60, 2.0],
            '179 seconds' => [179, 2.0],
            '3 minutes'   => [180, 3.0],
            '299 seconds' => [299, 3.0],
            '5 minutes'   => [300, 4.0],
            'never > 4km' => [3600, 4.0],
        ];
    }
}
