<?php
namespace Modules\Driver\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\RTDBService;

class DriverScoreService
{
    const DEFAULT_SCORE       = 80;
    const SCORE_DECLINE       = -3;
    const SCORE_TIMEOUT       = -1;
    const SCORE_STREAK_BONUS  = +1;
    const STREAK_THRESHOLD    = 2;   // 2 đơn liên tiếp để được cộng điểm
    const MIN_SCORE           = 0;
    const MAX_SCORE           = 100;
    const SUSPEND_THRESHOLD   = 20;  // điểm <= này thì bị khóa nhận đơn tạm thời
    const SUSPEND_HOURS       = 2;   // khóa 2 giờ

    public static function onDecline(int $driverId): void
    {
        self::adjust($driverId, self::SCORE_DECLINE, 'decline');
        self::resetStreak($driverId);
    }

    public static function onTimeout(int $driverId): void
    {
        self::adjust($driverId, self::SCORE_TIMEOUT, 'timeout');
        self::resetStreak($driverId);
    }

    public static function onComplete(int $driverId): void
    {
        $driver = DB::table('users')->where('id', $driverId)
            ->select('driver_score', 'consecutive_completed')
            ->first();

        if (!$driver) return;

        $streak = ($driver->consecutive_completed ?? 0) + 1;

        if ($streak >= self::STREAK_THRESHOLD) {
            self::adjust($driverId, self::SCORE_STREAK_BONUS, 'streak_bonus');
            $streak = 0;
        }

        DB::table('users')->where('id', $driverId)
            ->update(['consecutive_completed' => $streak]);
    }

    /**
     * Decay điểm cho tài xế không hoạt động.
     * Gọi từ command DecayDriverScores.
     */
    public static function onDecay(int $driverId, int $delta, string $reason): void
    {
        self::adjust($driverId, $delta, $reason);
    }

    private static function adjust(int $driverId, int $delta, string $reason): void
    {
        $current  = DB::table('users')->where('id', $driverId)->value('driver_score') ?? self::DEFAULT_SCORE;
        $newScore = max(self::MIN_SCORE, min(self::MAX_SCORE, $current + $delta));

        $updates = ['driver_score' => $newScore];

        // Khóa nhận đơn nếu điểm rớt xuống dưới ngưỡng
        if ($newScore <= self::SUSPEND_THRESHOLD && $current > self::SUSPEND_THRESHOLD) {
            $suspendUntil = now()->addHours(self::SUSPEND_HOURS);
            $updates['score_suspended_until'] = $suspendUntil;
            Log::warning("[DriverScore] Driver #{$driverId} bị khóa nhận đơn đến {$suspendUntil} do điểm rớt xuống {$newScore}");
        }

        DB::table('users')->where('id', $driverId)->update($updates);
        DB::table('driver_score_logs')->insert([
            'driver_id'    => $driverId,
            'delta'        => $delta,
            'score_before' => $current,
            'score_after'  => $newScore,
            'reason'       => $reason,
            'created_at'   => now(),
        ]);

        Log::info("[DriverScore] Driver #{$driverId} {$reason}: {$current} → {$newScore} (Δ{$delta})");

        // Ping RTDB để driver app tự refresh điểm
        RTDBService::pingDriverScore($driverId);
    }

    public static function onRated(int $driverId, int $stars): void
    {
        $delta = match ($stars) {
            5 => +1,
            4 =>  0,
            3 =>  0,
            2 => -2,
            1 => -5,
            default => 0,
        };

        if ($delta !== 0) {
            self::adjust($driverId, $delta, "rated_{$stars}_stars");
        }
    }

    public static function resetToDefault(int $driverId): void
    {
        $current = DB::table('users')->where('id', $driverId)->value('driver_score') ?? self::DEFAULT_SCORE;
        DB::table('users')->where('id', $driverId)->update([
            'driver_score'           => self::DEFAULT_SCORE,
            'score_suspended_until'  => null,
        ]);
        Log::info("[DriverScore] Driver #{$driverId} score reset: {$current} → " . self::DEFAULT_SCORE);
    }

    private static function resetStreak(int $driverId): void
    {
        DB::table('users')->where('id', $driverId)->update(['consecutive_completed' => 0]);
    }

    public static function label(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Xuất sắc',
            $score >= 60 => 'Tốt',
            $score >= 40 => 'Trung bình',
            $score >= 20 => 'Cần cải thiện',
            default      => 'Kém',
        };
    }

    public static function tips(int $score, int $streak): array
    {
        $tips = [];

        if ($streak > 0) {
            $left   = self::STREAK_THRESHOLD - $streak;
            $tips[] = "Hoàn thành thêm {$left} đơn liên tiếp để nhận +" . self::SCORE_STREAK_BONUS . " điểm";
        } else {
            $tips[] = "Hoàn thành " . self::STREAK_THRESHOLD . " đơn liên tiếp để nhận +" . self::SCORE_STREAK_BONUS . " điểm";
        }

        if ($score <= self::SUSPEND_THRESHOLD) {
            $tips[] = "Điểm quá thấp — bạn đang bị tạm khóa nhận đơn " . self::SUSPEND_HOURS . " giờ";
        } elseif ($score < 60) {
            $tips[] = "Chấp nhận đơn nhanh để tránh mất điểm timeout (-" . abs(self::SCORE_TIMEOUT) . " điểm)";
        }

        return $tips;
    }
}
