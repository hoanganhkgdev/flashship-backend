<?php
namespace Modules\Order\Services;

use Modules\Core\Services\OperationalSettings;

/** Giới hạn khoảng cách đường thực tế khi tìm tài xế. */
class DispatchRadiusPolicy
{
    public static function radiusForElapsedSeconds(int $seconds): float
    {
        // CandidateFinder đã quét toàn thành phố và xếp hạng theo khoảng
        // cách/điểm/thời gian chờ. Các vòng 1→2→3km chỉ làm đơn phải đợi
        // nhiều phút khi tài xế duy nhất ở 2–4km, dù không có ai gần hơn.
        // Quét trọn trần cấu hình ngay; người tốt nhất vẫn đứng đầu và tuyệt đối
        // không phát vượt trần được admin cấu hình.
        return OperationalSettings::dispatchMaxRoadDistanceKm();
    }
}
