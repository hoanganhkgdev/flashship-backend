<?php

namespace Modules\Order\Services;

use Illuminate\Support\Collection;
use Modules\Driver\Services\DriverLocationService;

final class DispatchGpsFreshnessPolicy
{
    /**
     * Giữ nguyên thứ tự xếp hạng trong từng nhóm, nhưng luôn đặt tài xế có
     * GPS <= 90 giây trước nhóm đang dùng vùng đệm mạng yếu 91–180 giây.
     */
    public static function prioritize(Collection $drivers): Collection
    {
        [$fresh, $grace] = $drivers->partition(
            fn ($driver) => (int) ($driver->getAttribute('_gps_age_seconds') ?? 0)
                <= DriverLocationService::POS_FRESH_PRIORITY_SECS,
        );

        return $fresh->concat($grace)->values();
    }
}
