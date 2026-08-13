<?php
namespace Modules\Order\Services;

use Carbon\Carbon;
use Modules\Core\Models\User;
use Modules\Driver\Services\DriverScoreService;

/**
 * Thuần logic chấm điểm/xếp hạng ứng viên tài xế — không đụng DB/Redis/RTDB,
 * chỉ nhận giá trị đã tính sẵn (driver model, khoảng cách) và trả về điểm.
 * Đây là nơi đổi luật/trọng số chấm điểm sau này.
 */
class DispatchScoringCalculator
{
    const W_SCORE         = 15;
    const W_WAIT_TIME     = 42.5;
    const W_DISTANCE      = 42.5;
    // 2026-08-13: bỏ tiêu chí số lượt đánh giá (chưa cần thiết), dồn điểm cho
    // chờ lâu + khoảng cách bằng nhau — ưu tiên tài xế gần hơn để giảm thời
    // gian chờ của khách, nhưng vẫn giữ công bằng cho tài xế chờ lâu chứ
    // không để khoảng cách áp đảo hoàn toàn.
    //
    // Phát đơn KHÔNG phân biệt trong/ngoài ca — công bằng như nhau cho mọi
    // tài xế online. Trách nhiệm đăng ký ca chỉ còn tính qua chấm điểm
    // (luật riêng — % online trong ca, cuối ca), không còn ảnh hưởng thứ tự
    // nhận đơn nữa (trước đây có cộng điểm ưu tiên mềm W_IN_SHIFT, đã bỏ).

    const WAIT_TIME_CAP_MINS = 480; // 8 tiếng — tài xế chờ lâu được ưu tiên rõ hơn

    public function scoreComponent(User $driver): float
    {
        return ($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE) / DriverScoreService::MAX_SCORE * self::W_SCORE;
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

    public function composite(User $driver, float $distanceKm, float $distanceCapKm): float
    {
        return $this->scoreComponent($driver)
            + $this->waitTimeScore($driver)
            + $this->distanceComponent($distanceKm, $distanceCapKm);
    }
}
