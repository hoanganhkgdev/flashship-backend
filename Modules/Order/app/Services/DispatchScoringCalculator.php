<?php
namespace Modules\Order\Services;

use Carbon\Carbon;
use Modules\Core\Models\User;
use Modules\Driver\Services\DriverScoreService;

/**
 * Thuần logic chấm điểm/xếp hạng ứng viên tài xế — không đụng DB/Redis/RTDB,
 * chỉ nhận giá trị đã tính sẵn (driver model, số lượt đánh giá, khoảng cách)
 * và trả về điểm. Đây là nơi đổi luật/trọng số chấm điểm sau này.
 */
class DispatchScoringCalculator
{
    const W_SCORE         = 15;
    const W_RATING_CNT    = 10;
    const W_WAIT_TIME     = 50;
    const W_DISTANCE      = 25;
    // Phát đơn KHÔNG phân biệt trong/ngoài ca — công bằng như nhau cho mọi
    // tài xế online. Trách nhiệm đăng ký ca chỉ còn tính qua chấm điểm
    // (luật riêng — % online trong ca, cuối ca), không còn ảnh hưởng thứ tự
    // nhận đơn nữa (trước đây có cộng điểm ưu tiên mềm W_IN_SHIFT, đã bỏ).

    const WAIT_TIME_CAP_MINS = 480; // 8 tiếng — tài xế chờ lâu được ưu tiên rõ hơn
    const RATING_COUNT_CAP   = 200;

    public function scoreComponent(User $driver): float
    {
        return ($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE) / DriverScoreService::MAX_SCORE * self::W_SCORE;
    }

    public function ratingCountComponent(int $ratingCount): float
    {
        return min($ratingCount, self::RATING_COUNT_CAP) / self::RATING_COUNT_CAP * self::W_RATING_CNT;
    }

    public function waitTimeScore(User $driver): float
    {
        $since = $driver->last_order_completed_at ?? $driver->online_since;
        if (!$since) return 0;
        $waitMins = min(self::WAIT_TIME_CAP_MINS, abs(now()->diffInMinutes(Carbon::parse($since))));
        return ($waitMins / self::WAIT_TIME_CAP_MINS) * self::W_WAIT_TIME;
    }

    public function distanceComponent(float $distanceKm, float $distanceCapKm): float
    {
        return (1 - min($distanceKm, $distanceCapKm) / $distanceCapKm) * self::W_DISTANCE;
    }

    public function composite(User $driver, int $ratingCount, float $distanceKm, float $distanceCapKm): float
    {
        return $this->scoreComponent($driver)
            + $this->ratingCountComponent($ratingCount)
            + $this->waitTimeScore($driver)
            + $this->distanceComponent($distanceKm, $distanceCapKm);
    }
}
