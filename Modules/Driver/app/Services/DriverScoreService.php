<?php
namespace Modules\Driver\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\RTDBService;
use Modules\Core\Services\OperationalSettings;

class DriverScoreService
{
    const DEFAULT_SCORE        = 100;
    const MIN_SCORE            = 0;

    // ─── Triggers ────────────────────────────────────────────────────────────────

    public static function onComplete(int $driverId): void
    {
        DB::transaction(function () use ($driverId) {
            $driver = DB::table('users')
                ->where('id', $driverId)
                ->lockForUpdate()
                ->select('driver_score', 'consecutive_completed', 'city_id')
                ->first();

            $streak     = (int) ($driver->consecutive_completed ?? 0) + 1;
            $milestones = self::streakMilestones($driver->city_id);
            $bonusDelta = $milestones[$streak] ?? 0;

            $newStreak = $streak >= max(array_keys($milestones)) ? 0 : $streak;

            DB::table('users')->where('id', $driverId)->update([
                'consecutive_completed'   => $newStreak,
                'driver_last_active_date' => now()->toDateString(),
            ]);

            if ($bonusDelta > 0) {
                self::adjust($driverId, $bonusDelta, "streak_{$streak}", $driver->driver_score);
            } else {
                DB::table('driver_score_logs')->insert([
                    'driver_id'    => $driverId,
                    'delta'        => 0,
                    'score_before' => $driver->driver_score ?? self::DEFAULT_SCORE,
                    'score_after'  => $driver->driver_score ?? self::DEFAULT_SCORE,
                    'reason'       => 'complete',
                    'created_at'   => now(),
                ]);
            }
        });
    }

    public static function onDecline(int $driverId): void
    {
        self::adjustWithStreakReset($driverId, self::declinePenalty(self::cityOf($driverId)), 'decline');
    }

    /**
     * Đã mở xem đơn nhưng để hết giờ không bấm gì — cùng mức với từ chối chủ
     * động (-2), vì cả 2 đều là né đơn có chủ ý, chỉ khác cách thể hiện.
     */
    public static function onViewedTimeout(int $driverId): void
    {
        self::adjustWithStreakReset($driverId, self::viewedTimeoutPenalty(self::cityOf($driverId)), 'viewed_timeout');
    }

    /**
     * Chấm điểm cuối ca dựa trên % thời gian online / tổng thời lượng ca —
     * thay cho luật "8h/ngày" cũ VÀ luật "không hoạt động cả ngày" cũ
     * (onInactivity, đã bỏ — dư thừa vì không đăng ký ca đã coi là nghỉ
     * không phạt, có đăng ký mà 0% đã bị tier <50% xử lý nặng hơn mức cũ).
     * Không còn mốc cộng điểm — tầng online cao nhất chỉ trung lập (0đ), vì online đủ ca
     * là kỳ vọng tối thiểu chứ không phải thành tích để thưởng thêm.
     * Gọi từ ScoreShiftSessionsCommand.
     */
    public static function onShiftOnlineRate(int $driverId, float $percent): void
    {
        $cityId = self::cityOf($driverId);
        $thresholds = OperationalSettings::shiftOnlineThresholds($cityId);
        $penalties = OperationalSettings::shiftOnlinePenalties($cityId);
        [$delta, $reason] = match (true) {
            $percent >= $thresholds['normal'] => [0, 'shift_online_normal'],
            $percent >= $thresholds['reduced'] => [$penalties['reduced'], 'shift_online_reduced'],
            $percent >= $thresholds['mid'] => [$penalties['mid'], 'shift_online_mid'],
            $percent >= $thresholds['low'] => [$penalties['low'], 'shift_online_low'],
            default => [$penalties['critical'], 'shift_online_critical'],
        };

        // Khoá dòng driver trước khi cộng/trừ điểm — các hàm chấm điểm khác
        // (onComplete/onDecline/onUnviewedOfferWindowLimit, qua khoá dòng)
        // đều khoá trước khi gọi adjust(), hàm này trước đây thiếu, có thể
        // mất 1 lần cộng/trừ nếu trùng lúc cron chấm ca với 1 sự kiện chấm
        // điểm real-time khác cho cùng tài xế.
        DB::transaction(function () use ($driverId, $delta, $reason) {
            DB::table('users')->where('id', $driverId)->lockForUpdate()->select('id')->first();
            self::adjust($driverId, $delta, $reason);
        });
    }

    /** Gọi khi đã xác nhận có 3 offer không mở trong cửa sổ 5 offer ACK. */
    public static function onUnviewedOfferWindowLimit(int $driverId): void
    {
        self::adjustWithStreakReset($driverId, self::unviewedPenalty(self::cityOf($driverId)), 'offer_unviewed_x3');
    }

    // ─── Weekly Reset ────────────────────────────────────────────────────────────

    public static function weeklyReset(): void
    {
        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->get(['id', 'driver_score']);

        foreach ($drivers as $d) {
            $old = (int) ($d->driver_score ?? self::DEFAULT_SCORE);

            DB::table('users')->where('id', $d->id)->update([
                'driver_score'          => self::DEFAULT_SCORE,
                'consecutive_completed' => 0,
                'daily_bonus_points'    => 0,
            ]);

            DB::table('driver_score_logs')->insert([
                'driver_id'    => $d->id,
                'delta'        => self::DEFAULT_SCORE - $old,
                'score_before' => $old,
                'score_after'  => self::DEFAULT_SCORE,
                'reason'       => 'weekly_reset',
                'created_at'   => now(),
            ]);
        }

        Log::info("[DriverScore] Weekly reset: {$drivers->count()} tài xế reset về " . self::DEFAULT_SCORE);
    }

    // ─── Core ────────────────────────────────────────────────────────────────────

    private static function adjustWithStreakReset(
        int $driverId,
        int $delta,
        string $reason,
    ): void
    {
        DB::transaction(function () use ($driverId, $delta, $reason) {
            DB::table('users')->where('id', $driverId)->lockForUpdate()->select('id')->first();
            DB::table('users')->where('id', $driverId)->update([
                'consecutive_completed' => 0,
            ]);
            self::adjust($driverId, $delta, $reason);
        });
    }

    private static function adjust(int $driverId, int $delta, string $reason, ?int $knownCurrent = null): void
    {
        $today   = now()->toDateString();
        $cityId  = self::cityOf($driverId);
        $current = $knownCurrent ?? (int) (DB::table('users')->where('id', $driverId)->value('driver_score') ?? self::DEFAULT_SCORE);

        if ($delta > 0) {
            $row = DB::table('users')
                ->where('id', $driverId)
                ->select('daily_bonus_points', 'daily_bonus_date')
                ->first();

            $earned = ($row?->daily_bonus_date === $today) ? (int) ($row->daily_bonus_points ?? 0) : 0;
            $remaining = self::dailyBonusCap($cityId) - $earned;

            if ($remaining <= 0) {
                Log::info("[DriverScore] Driver #{$driverId} daily cap reached — {$reason} blocked.");
                DB::table('driver_score_logs')->insert([
                    'driver_id'    => $driverId,
                    'delta'        => 0,
                    'score_before' => $current,
                    'score_after'  => $current,
                    'reason'       => "cap_blocked:{$reason}",
                    'created_at'   => now(),
                ]);
                return;
            }

            $delta = min($delta, $remaining);

            DB::table('users')->where('id', $driverId)->update([
                'daily_bonus_points' => $earned + $delta,
                'daily_bonus_date'   => $today,
            ]);
        }

        $newScore = max(self::MIN_SCORE, min(self::maxScore($cityId), $current + $delta));

        DB::table('users')->where('id', $driverId)->update(['driver_score' => $newScore]);

        DB::table('driver_score_logs')->insert([
            'driver_id'    => $driverId,
            'delta'        => $delta,
            'score_before' => $current,
            'score_after'  => $newScore,
            'reason'       => $reason,
            'created_at'   => now(),
        ]);

        Log::info("[DriverScore] Driver #{$driverId} {$reason}: {$current} → {$newScore} (Δ{$delta})");
        RTDBService::pingDriverScore($driverId, $newScore);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    public static function resetToDefault(int $driverId): void
    {
        $current = DB::table('users')->where('id', $driverId)->value('driver_score') ?? self::DEFAULT_SCORE;
        DB::table('users')->where('id', $driverId)->update([
            'driver_score'          => self::DEFAULT_SCORE,
            'consecutive_completed' => 0,
        ]);
        Log::info("[DriverScore] Driver #{$driverId} reset: {$current} → " . self::DEFAULT_SCORE);
    }

    public static function label(int $score, ?int $cityId): string
    {
        return match (true) {
            $score >= self::weeklyBonusScore($cityId) => 'Xuất sắc',
            $score >= 110 => 'Tốt',
            $score >= 90  => 'Khá',
            $score > self::weeklyPenaltyScore($cityId) => 'Trung bình',
            default       => 'Cần cải thiện',
        };
    }

    public static function tips(int $score, ?int $cityId): array
    {
        $bonusScore = self::weeklyBonusScore($cityId);
        $penaltyScore = self::weeklyPenaltyScore($cityId);
        $bonusAmount   = number_format(self::weeklyBonusAmount($cityId), 0, ',', '.') . '₫';
        $penaltyAmount = number_format(self::weeklyPenaltyAmount($cityId), 0, ',', '.') . '₫';

        if ($score >= $bonusScore) {
            return ['Bạn đã đạt ' . $bonusScore . ' điểm — tiếp tục duy trì để nhận thưởng ' . $bonusAmount . ' cuối tuần!'];
        }
        if ($score <= $penaltyScore) {
            return ['Điểm từ ' . $penaltyScore . ' trở xuống — cố gắng cải thiện để tránh bị phạt ' . $penaltyAmount . ' cuối tuần.'];
        }
        return ['Cần thêm ' . ($bonusScore - $score) . ' điểm để đạt thưởng ' . $bonusAmount . ' cuối tuần.'];
    }

    public static function maxScore(?int $cityId): int
    {
        return OperationalSettings::maxDriverScore($cityId);
    }

    public static function streakMilestones(?int $cityId): array
    {
        return OperationalSettings::streakMilestones($cityId);
    }

    public static function dailyBonusCap(?int $cityId): int
    {
        return OperationalSettings::dailyBonusCap($cityId);
    }

    public static function weeklyBonusScore(?int $cityId): int
    {
        return OperationalSettings::weeklyBonusScore($cityId);
    }

    public static function weeklyPenaltyScore(?int $cityId): int
    {
        return OperationalSettings::weeklyPenaltyScore($cityId);
    }

    public static function weeklyBonusAmount(?int $cityId): int
    {
        return OperationalSettings::weeklyBonusAmount($cityId);
    }

    public static function weeklyPenaltyAmount(?int $cityId): int
    {
        return OperationalSettings::weeklyPenaltyAmount($cityId);
    }

    public static function declinePenalty(?int $cityId): int
    {
        return OperationalSettings::scoreDeclinePenalty($cityId);
    }

    public static function viewedTimeoutPenalty(?int $cityId): int
    {
        return OperationalSettings::scoreViewedTimeoutPenalty($cityId);
    }

    public static function unviewedPenalty(?int $cityId): int
    {
        return OperationalSettings::scoreUnviewedPenalty($cityId);
    }

    /** Khu vực của tài xế — điểm/ví/công nợ tính theo cấu hình khu vực đó. */
    public static function cityOf(int $driverId): ?int
    {
        $cityId = DB::table('users')->where('id', $driverId)->value('city_id');

        return $cityId !== null ? (int) $cityId : null;
    }
}
