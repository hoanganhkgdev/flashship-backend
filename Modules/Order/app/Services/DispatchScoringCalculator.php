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
    // Điểm nền kiểu Bayesian: tài xế mới bắt đầu ở mức khá/trung tính, không
    // được 100% chỉ vì chưa từng bị từ chối, cũng không bị xếp cuối vì chưa có
    // lịch sử. Khi số mẫu tăng, dữ liệu thật dần thay thế điểm nền.
    private const RESPONSE_PRIOR_TOTAL = 5;

    private const RESPONSE_PRIOR_ACCEPTED = 4;

    private const RATING_PRIOR_COUNT = 5;

    private const RATING_PRIOR_AVERAGE = 4.5;

    private const RESPONSE_SHARE = 0.50;

    private const RATING_SHARE = 0.30;

    private const OPERATIONAL_SCORE_SHARE = 0.20;

    // $cityId là khu vực của đơn — trọng số xếp hạng theo cấu hình khu vực đó.
    public function scoreComponent(User $driver, ?int $cityId): float
    {
        return $this->servicePerformance($driver, $cityId)
            * $this->normalizedWeights($cityId)['score'];
    }

    /** Điểm hiệu suất 0..1: phản hồi offer + đánh giá khách + điểm vận hành. */
    public function servicePerformance(User $driver, ?int $cityId): float
    {
        $accepted = max(0, (int) $driver->getAttribute('_dispatch_accepted_count'));
        $actionable = max($accepted, (int) $driver->getAttribute('_dispatch_actionable_count'));
        $responseRate = ($accepted + self::RESPONSE_PRIOR_ACCEPTED)
            / ($actionable + self::RESPONSE_PRIOR_TOTAL);

        $ratingCount = max(0, (int) $driver->getAttribute('_dispatch_rating_count'));
        $ratingSum = max(0, (float) $driver->getAttribute('_dispatch_rating_sum'));
        $ratingAverage = ($ratingSum + self::RATING_PRIOR_AVERAGE * self::RATING_PRIOR_COUNT)
            / ($ratingCount + self::RATING_PRIOR_COUNT);
        $ratingRate = min(1, max(0, $ratingAverage / 5));

        $maxScore = max(1, DriverScoreService::maxScore($cityId));
        $score = min($maxScore, max(0, (int) ($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE)));
        $operationalRate = $score / $maxScore;

        return min(1, max(0,
            $responseRate * self::RESPONSE_SHARE
            + $ratingRate * self::RATING_SHARE
            + $operationalRate * self::OPERATIONAL_SCORE_SHARE
        ));
    }

    public function waitTimeScore(User $driver, ?int $cityId): float
    {
        $since = $this->waitStartedAt($driver);
        if (! $since || $since->isFuture()) {
            return 0;
        }

        $waitCap = max(1, OperationalSettings::dispatchWaitCapMinutes($cityId));
        $waitMins = min($waitCap, $since->diffInMinutes(now()));

        return ($waitMins / $waitCap) * $this->normalizedWeights($cityId)['wait'];
    }

    public function distanceComponent(float $distanceKm, float $distanceCapKm, ?int $cityId): float
    {
        $distanceCapKm = max(0.001, $distanceCapKm);
        $distanceKm = max(0, $distanceKm);

        return (1 - min($distanceKm, $distanceCapKm) / $distanceCapKm)
            * $this->normalizedWeights($cityId)['distance'];
    }

    public function composite(User $driver, float $distanceKm, float $distanceCapKm, ?int $cityId): float
    {
        return $this->scoreComponent($driver, $cityId)
            + $this->waitTimeScore($driver, $cityId)
            + $this->distanceComponent($distanceKm, $distanceCapKm, $cityId);
    }

    /**
     * Thời gian chờ chỉ bắt đầu từ lúc tài xế thật sự sẵn sàng nhận đơn:
     * mốc muộn hơn giữa lần bật Online và lần hoàn thành đơn gần nhất.
     * Vì vậy thời gian Offline qua đêm không được cộng vào ưu tiên.
     */
    private function waitStartedAt(User $driver): ?Carbon
    {
        $timestamps = collect([$driver->online_since, $driver->last_order_completed_at])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value));

        return $timestamps->sortByDesc(fn (Carbon $value) => $value->getTimestamp())->first();
    }

    /**
     * Trang cấu hình mô tả ba trọng số là tỷ lệ tương đối và không bắt buộc
     * tổng bằng 100. Chuẩn hóa về thang 100 giữ cho fair_score_band luôn có
     * cùng ý nghĩa dù admin nhập 15/42.5/42.5 hay 30/85/85.
     *
     * @return array{score: float, wait: float, distance: float}
     */
    private function normalizedWeights(?int $cityId): array
    {
        $weights = OperationalSettings::dispatchWeights($cityId);
        $weights = array_map(fn ($weight) => max(0, (float) $weight), $weights);
        $total = array_sum($weights);

        if ($total <= 0) {
            return ['score' => 0, 'wait' => 50, 'distance' => 50];
        }

        return [
            'score' => $weights['score'] / $total * 100,
            'wait' => $weights['wait'] / $total * 100,
            'distance' => $weights['distance'] / $total * 100,
        ];
    }
}
