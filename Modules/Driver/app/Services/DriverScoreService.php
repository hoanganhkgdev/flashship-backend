<?php
namespace Modules\Driver\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DriverScoreService
{
    const DEFAULT_SCORE      = 80;
    const SCORE_DECLINE      = -5;
    const SCORE_TIMEOUT      = -5; // bỏ qua = từ chối, phạt như nhau
    const SCORE_STREAK_BONUS = +5;
    const STREAK_THRESHOLD   = 2;   // số đơn liên tiếp để được cộng điểm
    const MIN_SCORE          = 0;
    const MAX_SCORE          = 100;

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

    private static function adjust(int $driverId, int $delta, string $reason): void
    {
        $current  = DB::table('users')->where('id', $driverId)->value('driver_score') ?? self::DEFAULT_SCORE;
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
    }

    public static function onRated(int $driverId, int $stars): void
    {
        $delta = match ($stars) {
            5 => +3,
            4 => +1,
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
        DB::table('users')->where('id', $driverId)->update(['driver_score' => self::DEFAULT_SCORE]);
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
            $left = self::STREAK_THRESHOLD - $streak;
            $tips[] = "Hoàn thành thêm {$left} đơn liên tiếp để nhận +" . self::SCORE_STREAK_BONUS . " điểm";
        } else {
            $tips[] = "Hoàn thành " . self::STREAK_THRESHOLD . " đơn liên tiếp để nhận +" . self::SCORE_STREAK_BONUS . " điểm";
        }

        if ($score < 60) {
            $tips[] = "Chấp nhận đơn nhanh để tránh mất điểm timeout (-" . abs(self::SCORE_TIMEOUT) . " điểm)";
        }

        return $tips;
    }
}
