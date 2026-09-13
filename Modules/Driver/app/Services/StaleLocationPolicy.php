<?php

namespace Modules\Driver\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class StaleLocationPolicy
{
    public const STALE_AFTER_SECONDS = 120;

    public function expiresAt(?array $location, CarbonInterface $onlineSince, CarbonInterface $now): CarbonImmutable
    {
        $lastEvidenceAt = CarbonImmutable::instance($onlineSince);
        $updatedAt = $location['updated_at'] ?? null;

        if (isset($location['lat'], $location['lng']) && is_numeric($updatedAt)) {
            $gpsAt = CarbonImmutable::createFromTimestampUTC((int) ($updatedAt / 1000));

            // Không tin timestamp tương lai từ dữ liệu lỗi. Tọa độ cũ của
            // phiên trước cũng không được làm phiên Online mới hết hạn sớm.
            if ($gpsAt->lessThanOrEqualTo($now->copy()->addSeconds(5))
                && $gpsAt->greaterThan($lastEvidenceAt)) {
                $lastEvidenceAt = $gpsAt;
            }
        }

        return $lastEvidenceAt->addSeconds(self::STALE_AFTER_SECONDS);
    }
}
