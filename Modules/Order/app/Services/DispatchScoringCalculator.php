<?php
namespace Modules\Order\Services;

use Carbon\Carbon;
use Modules\Core\Models\User;
use Modules\Core\Services\OperationalSettings;
use Modules\Driver\Services\DriverScoreService;

/**
 * Thuần logic chấm điểm/xếp hạng ứng viên tài xế — không đụng DB/Redis/RTDB,
 * chỉ nhận giá trị đã tính sẵn (driver model, khoảng cách) và trả về điểm.
 * Đây là nơi đổi luật/trọng số chấm điểm sau này.
 */
class DispatchScoringCalculator
{
    // 2026-08-13: bỏ tiêu chí số lượt đánh giá (chưa cần thiết), dồn điểm cho
    // chờ lâu + khoảng cách bằng nhau — ưu tiên tài xế gần hơn để giảm thời
    // gian chờ của khách, nhưng vẫn giữ công bằng cho tài xế chờ lâu chứ
    // không để khoảng cách áp đảo hoàn toàn.
    //
    // Phát đơn KHÔNG phân biệt trong/ngoài ca — công bằng như nhau cho mọi
    // tài xế online. Trách nhiệm đăng ký ca chỉ còn tính qua chấm điểm
    // (luật riêng — % online trong ca, cuối ca), không còn ảnh hưởng thứ tự
    // nhận đơn nữa (trước đây có cộng điểm ưu tiên mềm W_IN_SHIFT, đã bỏ).

    // $cityId là khu vực của đơn — trọng số xếp hạng theo cấu hình khu vực đó.
    public function scoreComponent(User $driver, ?int $cityId): float
    {
        return ($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE)
            / DriverScoreService::maxScore($cityId)
            * OperationalSettings::dispatchWeights($cityId)['score'];
    }

    public function waitTimeScore(User $driver, ?int $cityId): float
    {
        $since = $driver->last_order_completed_at ?? $driver->online_since;
        if (!$since) return 0;
        $waitCap = OperationalSettings::dispatchWaitCapMinutes($cityId);
        $waitMins = min($waitCap, abs(now()->diffInMinutes(Carbon::parse($since))));
        return ($waitMins / $waitCap) * OperationalSettings::dispatchWeights($cityId)['wait'];
    }

    public function distanceComponent(float $distanceKm, float $distanceCapKm, ?int $cityId): float
    {
        return (1 - min($distanceKm, $distanceCapKm) / $distanceCapKm)
            * OperationalSettings::dispatchWeights($cityId)['distance'];
    }

    public function composite(User $driver, float $distanceKm, float $distanceCapKm, ?int $cityId): float
    {
        return $this->scoreComponent($driver, $cityId)
            + $this->waitTimeScore($driver, $cityId)
            + $this->distanceComponent($distanceKm, $distanceCapKm, $cityId);
    }
}
