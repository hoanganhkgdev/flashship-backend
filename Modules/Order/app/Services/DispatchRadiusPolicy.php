<?php
namespace Modules\Order\Services;

/** Quy tắc mở rộng phạm vi tìm tài xế theo thời gian chờ. */
class DispatchRadiusPolicy
{
    public const MAX_ROAD_DISTANCE_KM = 4.0;

    public static function radiusForElapsedSeconds(int $seconds): float
    {
        return match (true) {
            $seconds < 60  => 1.0,
            $seconds < 180 => 2.0,
            $seconds < 300 => 3.0,
            default        => self::MAX_ROAD_DISTANCE_KM,
        };
    }
}
