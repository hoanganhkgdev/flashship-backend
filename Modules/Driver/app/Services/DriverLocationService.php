<?php
namespace Modules\Driver\Services;

use Modules\Core\Services\RTDBService;

/**
 * Đọc toạ độ tài xế trực tiếp từ Firebase RTDB tại thời điểm cần dùng
 * (dispatch, tìm tài xế gần, tổng đài) — thay cho việc đọc bản sao MySQL
 * do cron `driver:sync-location` từng ghi (đã bỏ, xem lịch sử refactor GPS).
 */
class DriverLocationService
{
    // Kế thừa từ ngưỡng "GPS mới hơn 10 phút" / "heartbeat trong 15 phút"
    // trước đây dùng bởi SyncDriverLocationCommand.
    const POS_MAX_AGE_SECS = 600;
    const HB_MAX_AGE_SECS  = 900;

    /**
     * @param  array<int> $driverIds
     * @return array<int, array{lat: float, lng: float, bearing: ?float}> chỉ gồm tài xế có vị trí đủ mới
     */
    public function freshLocationsFor(array $driverIds): array
    {
        $raw = RTDBService::getDriverLocations();
        $now = time();
        $out = [];

        foreach ($driverIds as $id) {
            $data = $raw["driver_{$id}"] ?? null;
            if (!$data || !isset($data['lat'], $data['lng'])) {
                continue;
            }

            $updatedAt = $data['updated_at'] ?? 0;
            $posAge    = is_numeric($updatedAt) ? $now - (int) ($updatedAt / 1000) : PHP_INT_MAX;
            if ($posAge > self::POS_MAX_AGE_SECS) {
                continue;
            }

            $hbMs  = max((int) ($data['heartbeat_at'] ?? 0), (int) $updatedAt);
            $hbAge = $hbMs > 0 ? $now - (int) ($hbMs / 1000) : PHP_INT_MAX;
            if ($hbAge > self::HB_MAX_AGE_SECS) {
                continue;
            }

            $out[$id] = [
                'lat'     => (float) $data['lat'],
                'lng'     => (float) $data['lng'],
                'bearing' => isset($data['bearing']) ? (float) $data['bearing'] : null,
            ];
        }

        return $out;
    }
}
