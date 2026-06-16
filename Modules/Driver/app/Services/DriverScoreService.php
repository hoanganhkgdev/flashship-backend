<?php
namespace Modules\Driver\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\RTDBService;

class DriverScoreService
{
    const DEFAULT_SCORE        = 100;
    const MIN_SCORE            = 0;
    const MAX_SCORE            = 150;

    const SCORE_COMPLETE       = 1;
    const SCORE_DECLINE        = -2;

    const RATING_DELTAS        = [5 => 1, 4 => 0, 3 => -1, 2 => -3, 1 => -5];

    const WEEKLY_BONUS_SCORE   = 150;
    const WEEKLY_PENALTY_SCORE = 70;
    const WEEKLY_BONUS_AMOUNT  = 50_000;
    const WEEKLY_PENALTY_AMOUNT= 50_000;

    // ─── Triggers ────────────────────────────────────────────────────────────────

    public static function onComplete(int $driverId): void
    {
        self::adjust($driverId, self::SCORE_COMPLETE, 'complete');
    }

    public static function onDecline(int $driverId): void
    {
        self::adjust($driverId, self::SCORE_DECLINE, 'decline');
    }

    public static function onRated(int $driverId, int $stars): void
    {
        $delta = self::RATING_DELTAS[$stars] ?? 0;
        if ($delta !== 0) {
            self::adjust($driverId, $delta, "rated_{$stars}_stars");
        }
    }

    // ─── Core ────────────────────────────────────────────────────────────────────

    private static function adjust(int $driverId, int $delta, string $reason): void
    {
        $current  = (int) (DB::table('users')->where('id', $driverId)->value('driver_score') ?? self::DEFAULT_SCORE);
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

    public static function resetToDefault(int $driverId): void
    {
        $current = DB::table('users')->where('id', $driverId)->value('driver_score') ?? self::DEFAULT_SCORE;
        DB::table('users')->where('id', $driverId)->update(['driver_score' => self::DEFAULT_SCORE]);
        Log::info("[DriverScore] Driver #{$driverId} reset: {$current} → " . self::DEFAULT_SCORE);
    }

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

    public static function tips(int $score): array
    {
        if ($score >= self::WEEKLY_BONUS_SCORE) {
            return ['Bạn đã đạt 150 điểm — tiếp tục duy trì để nhận thưởng 50.000₫ cuối tuần!'];
        }
        if ($score <= self::WEEKLY_PENALTY_SCORE) {
            return ['Điểm dưới 70 — cố gắng cải thiện để tránh bị phạt 50.000₫ cuối tuần.'];
        }
        return ['Cần thêm ' . (self::WEEKLY_BONUS_SCORE - $score) . ' điểm để đạt thưởng 50.000₫ cuối tuần.'];
    }
}
