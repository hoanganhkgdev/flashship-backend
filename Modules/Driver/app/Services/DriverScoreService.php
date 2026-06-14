<?php
namespace Modules\Driver\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\RTDBService;

class DriverScoreService
{
    const DEFAULT_SCORE      = 100;
    const MIN_SCORE          = 0;
    const MAX_SCORE          = 150;

    const SCORE_DECLINE      = -2;

    // Streak milestones: [consecutive_count => bonus_points]
    const STREAK_MILESTONES  = [3 => 1, 6 => 2, 10 => 4];

    // Rating deltas
    const RATING_DELTAS      = [5 => 1, 4 => 0, 3 => -1, 2 => -3, 1 => -5];

    // Giới hạn +10 điểm thưởng/ngày
    const DAILY_BONUS_CAP    = 10;

    // Ngưỡng chốt cuối tuần
    const WEEKLY_BONUS_SCORE   = 150;   // >= 150 → thưởng 50k
    const WEEKLY_PENALTY_SCORE = 70;    // <= 70  → phạt 50k
    const WEEKLY_BONUS_AMOUNT  = 50000;
    const WEEKLY_PENALTY_AMOUNT= 50000;

    // Online tối thiểu/ngày (giây)
    const MIN_ONLINE_SECONDS = 8 * 3600; // 8 giờ

    // ─── Triggers ────────────────────────────────────────────────────────────────

    public static function onDecline(int $driverId): void
    {
        self::adjust($driverId, self::SCORE_DECLINE, 'decline');
        self::resetStreak($driverId);
    }

    // Tài xế thấy offer nhưng không phản hồi trong 20s → trừ nhẹ, không reset streak
    public static function onTimeout(int $driverId): void
    {
        self::adjust($driverId, -1, 'timeout');
    }

    public static function onComplete(int $driverId): void
    {
        $driver = DB::table('users')->where('id', $driverId)
            ->select('consecutive_completed')
            ->first();

        if (!$driver) return;

        $streak = ($driver->consecutive_completed ?? 0) + 1;

        // Tặng điểm đúng tại mốc milestone
        foreach (self::STREAK_MILESTONES as $milestone => $bonus) {
            if ($streak === $milestone) {
                self::adjust($driverId, $bonus, "streak_{$milestone}");
                break;
            }
        }

        DB::table('users')->where('id', $driverId)
            ->update(['consecutive_completed' => $streak]);
    }

    public static function onRated(int $driverId, int $stars): void
    {
        $delta = self::RATING_DELTAS[$stars] ?? 0;
        if ($delta !== 0) {
            self::adjust($driverId, $delta, "rated_{$stars}_stars");
        }
    }

    public static function onDecay(int $driverId, int $delta, string $reason): void
    {
        self::adjust($driverId, $delta, $reason);
    }

    // ─── Adjust (core) ───────────────────────────────────────────────────────────

    private static function adjust(int $driverId, int $delta, string $reason): void
    {
        $driver = DB::table('users')->where('id', $driverId)
            ->select('driver_score', 'daily_bonus_points', 'daily_bonus_date')
            ->first();

        if (!$driver) return;

        $current = $driver->driver_score ?? self::DEFAULT_SCORE;

        // Áp dụng giới hạn +10 điểm thưởng/ngày
        if ($delta > 0) {
            $today = now()->toDateString();
            $todayBonus = ($driver->daily_bonus_date === $today)
                ? ($driver->daily_bonus_points ?? 0)
                : 0;

            $allowed = max(0, self::DAILY_BONUS_CAP - $todayBonus);
            $delta   = min($delta, $allowed);

            if ($delta === 0) {
                Log::info("[DriverScore] Driver #{$driverId} {$reason}: đã đạt giới hạn +10đ/ngày, bỏ qua.");
                return;
            }

            DB::table('users')->where('id', $driverId)->update([
                'daily_bonus_points' => $todayBonus + $delta,
                'daily_bonus_date'   => $today,
            ]);
        }

        $newScore = max(self::MIN_SCORE, min(self::MAX_SCORE, $current + $delta));

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

        RTDBService::pingDriverScore($driverId);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    private static function resetStreak(int $driverId): void
    {
        DB::table('users')->where('id', $driverId)->update(['consecutive_completed' => 0]);
    }

    public static function resetToDefault(int $driverId): void
    {
        $current = DB::table('users')->where('id', $driverId)->value('driver_score') ?? self::DEFAULT_SCORE;
        DB::table('users')->where('id', $driverId)->update([
            'driver_score'           => self::DEFAULT_SCORE,
            'score_suspended_until'  => null,
            'consecutive_completed'  => 0,
            'daily_bonus_points'     => 0,
            'daily_bonus_date'       => null,
        ]);
        Log::info("[DriverScore] Driver #{$driverId} reset: {$current} → " . self::DEFAULT_SCORE);
    }

    // ─── Weekly online time accumulation ────────────────────────────────────────

    /**
     * Gọi khi tài xế offline — cộng dồn thời gian online vào ngày hôm nay.
     */
    public static function accumulateOnlineTime(int $driverId, Carbon $onlineSince): void
    {
        $seconds = (int) $onlineSince->diffInSeconds(now());
        $today   = now()->toDateString();

        $driver = DB::table('users')->where('id', $driverId)
            ->select('daily_online_seconds', 'daily_online_date')
            ->first();

        if (!$driver) return;

        $accumulated = ($driver->daily_online_date === $today)
            ? ($driver->daily_online_seconds ?? 0)
            : 0;

        DB::table('users')->where('id', $driverId)->update([
            'daily_online_seconds' => $accumulated + $seconds,
            'daily_online_date'    => $today,
        ]);
    }

    // ─── Labels & tips ───────────────────────────────────────────────────────────

    public static function label(int $score): string
    {
        return match (true) {
            $score >= 130 => 'Xuất sắc',
            $score >= 110 => 'Tốt',
            $score >= 90  => 'Khá',
            $score >= 70  => 'Trung bình',
            default       => 'Cần cải thiện',
        };
    }

    public static function tips(int $score, int $streak): array
    {
        $tips = [];

        // Gợi ý streak
        foreach (self::STREAK_MILESTONES as $milestone => $bonus) {
            if ($streak < $milestone) {
                $left   = $milestone - $streak;
                $tips[] = "Hoàn thành thêm {$left} đơn liên tiếp để nhận +{$bonus} điểm (mốc {$milestone} đơn)";
                break;
            }
        }

        if ($score >= self::WEEKLY_BONUS_SCORE) {
            $tips[] = 'Bạn đã đạt 150 điểm — tiếp tục duy trì để nhận thưởng 50.000₫ cuối tuần!';
        } elseif ($score <= self::WEEKLY_PENALTY_SCORE) {
            $tips[] = 'Điểm dưới 70 — cố gắng cải thiện để tránh bị phạt 50.000₫ cuối tuần.';
        } else {
            $needed = self::WEEKLY_BONUS_SCORE - $score;
            $tips[] = "Cần thêm {$needed} điểm để đạt thưởng 50.000₫ cuối tuần.";
        }

        return $tips;
    }
}
