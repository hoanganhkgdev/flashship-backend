<?php
namespace Modules\Order\Services;

/** Giới hạn khoảng cách đường thực tế khi tìm tài xế. */
class DispatchRadiusPolicy
{
    public const MAX_ROAD_DISTANCE_KM = 4.0;

    public static function radiusForElapsedSeconds(int $seconds): float
    {
        // CandidateFinder đã quét toàn thành phố và xếp hạng theo khoảng
        // cách/điểm/thời gian chờ. Các vòng 1→2→3km chỉ làm đơn phải đợi
        // nhiều phút khi tài xế duy nhất ở 2–4km, dù không có ai gần hơn.
        // Quét trọn trần 4km ngay; người tốt nhất vẫn đứng đầu và tuyệt đối
        // không phát vượt MAX_ROAD_DISTANCE_KM.
        return self::MAX_ROAD_DISTANCE_KM;
    }
}
