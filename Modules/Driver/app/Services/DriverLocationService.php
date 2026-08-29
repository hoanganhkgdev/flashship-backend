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
    // App làm mới updated_at tối đa mỗi 20s kể cả khi đứng yên.
    // Cho vùng đệm 3 phút để không loại oan khi mạng/GPS chập chờn;
    // quá mốc này thì không thể phát đơn và không cộng giờ ca.
    const POS_MAX_AGE_SECS = 180;

    /**
     * @param  array<int> $driverIds
     * @return array<int, array{lat: float, lng: float, bearing: ?float}> chỉ gồm tài xế có vị trí đủ mới
     */
    public function freshLocationsFor(array $driverIds): array
    {
        // Khi Firebase lỗi, fail closed cho dispatch: không phát đơn dựa trên
        // vị trí không xác minh được. Cron chấm giờ xử lý null theo cách khác
        // (bỏ qua vòng quan sát), xem TrackGpsEligibleSessionsCommand.
        $raw = RTDBService::getDriverLocations() ?? [];
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

            $out[$id] = [
                'lat'     => (float) $data['lat'],
                'lng'     => (float) $data['lng'],
                'bearing' => isset($data['bearing']) ? (float) $data['bearing'] : null,
            ];
        }

        return $out;
    }
}
