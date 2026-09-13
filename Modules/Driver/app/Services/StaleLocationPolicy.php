<?php

namespace Modules\Driver\Services;

use Carbon\CarbonInterface;

class StaleLocationPolicy
{
    public const STALE_AFTER_SECONDS = 120;

    public const RESPONSE_GRACE_SECONDS = 60;

    public function lastEvidenceTimestamp(?array $location, CarbonInterface $onlineSince, CarbonInterface $now): int
    {
        $lastEvidenceTimestamp = $onlineSince->getTimestamp();
        $updatedAt = $location['updated_at'] ?? null;

        if (isset($location['lat'], $location['lng']) && is_numeric($updatedAt)) {
            $gpsTimestamp = (int) ($updatedAt / 1000);

            // Không tin timestamp tương lai từ dữ liệu lỗi. Tọa độ cũ của
            // phiên trước cũng không được làm phiên Online mới hết hạn sớm.
            if ($gpsTimestamp <= $now->getTimestamp() + 5
                && $gpsTimestamp > $lastEvidenceTimestamp) {
                $lastEvidenceTimestamp = $gpsTimestamp;
            }
        }

        return $lastEvidenceTimestamp;
    }

    public function warningDueTimestamp(?array $location, CarbonInterface $onlineSince, CarbonInterface $now): int
    {
        return $this->lastEvidenceTimestamp($location, $onlineSince, $now)
            + self::STALE_AFTER_SECONDS;
    }

    public function offlineDueTimestamp(CarbonInterface $notifiedAt): int
    {
        return $notifiedAt->getTimestamp() + self::RESPONSE_GRACE_SECONDS;
    }
}
