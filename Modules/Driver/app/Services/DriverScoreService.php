<?php
namespace Modules\Driver\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DriverScoreService
{
    const DEFAULT_SCORE      = 80;
    const SCORE_DECLINE      = -5;
    const SCORE_TIMEOUT      = -3;
    const SCORE_STREAK_BONUS = +5;
    const STREAK_THRESHOLD   = 2;   // số đơn liên tiếp để được cộng điểm
    const MIN_SCORE          = 0;
    const MAX_SCORE          = 100;

    // Ngưỡng điểm để vào từng đợt broadcast
    const WAVE_1_MIN = 60;  // đợt 1 — 5km ngay lập tức
    const WAVE_2_MIN = 30;  // đợt 2 — 10km sau 2 phút
                            // < 30   — đợt 3 sau 4 phút

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
        $current = DB::table('users')->where('id', $driverId)->value('driver_score') ?? self::DEFAULT_SCORE;
        $newScore = max(self::MIN_SCORE, min(self::MAX_SCORE, $current + $delta));

        DB::table('users')->where('id', $driverId)->update(['driver_score' => $newScore]);

        Log::info("[DriverScore] Driver #{$driverId} {$reason}: {$current} → {$newScore} (Δ{$delta})");
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
}
