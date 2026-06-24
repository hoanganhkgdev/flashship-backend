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

    const SCORE_DECLINE        = -2;
    const SCORE_TIMEOUT        = -2;

    const RATING_DELTAS        = [5 => 1, 4 => 0, 3 => -1, 2 => -3, 1 => -5];

    const STREAK_MILESTONES    = [3 => 1, 6 => 2, 10 => 4];
    const STREAK_RESET_AT      = 10;

    const DAILY_BONUS_CAP      = 10;

    const MIN_ONLINE_SECONDS   = 8 * 3600;

    const WEEKLY_BONUS_SCORE   = 150;
    const WEEKLY_PENALTY_SCORE = 70;
    const WEEKLY_BONUS_AMOUNT  = 50_000;
    const WEEKLY_PENALTY_AMOUNT= 50_000;

    // ─── Triggers ────────────────────────────────────────────────────────────────

    public static function onComplete(int $driverId): void
    {
        DB::transaction(function () use ($driverId) {
            $driver = DB::table('users')
                ->where('id', $driverId)
                ->lockForUpdate()
                ->select('driver_score', 'consecutive_completed')
                ->first();

            $streak     = (int) ($driver->consecutive_completed ?? 0) + 1;
            $bonusDelta = self::STREAK_MILESTONES[$streak] ?? 0;

            $newStreak = $streak >= self::STREAK_RESET_AT ? 0 : $streak;

            DB::table('users')->where('id', $driverId)->update([
                'consecutive_completed'   => $newStreak,
                'driver_last_active_date' => now()->toDateString(),
            ]);

            if ($bonusDelta > 0) {
                self::adjust($driverId, $bonusDelta, "streak_{$streak}", $driver->driver_score);
            }
        });
    }

    public static function onDecline(int $driverId): void
    {
        self::adjustWithStreakReset($driverId, self::SCORE_DECLINE, 'decline');
    }

    public static function onTimeout(int $driverId): void
    {
        self::adjustWithStreakReset($driverId, self::SCORE_TIMEOUT, 'timeout');
    }

    public static function onRated(int $driverId, int $stars): void
    {
        $delta = self::RATING_DELTAS[$stars] ?? 0;
        if ($delta !== 0) {
            self::adjust($driverId, $delta, "rated_{$stars}_stars");
        }
    }

    public static function onInactivity(int $driverId, int $days): void
    {
        $delta = $days >= 2 ? -10 : -5;
        self::adjust($driverId, $delta, "inactive_{$days}_day");
    }

    public static function onLowOnlineTime(int $driverId): void
    {
        self::adjust($driverId, -5, 'online_time_low');
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

    private static function adjustWithStreakReset(int $driverId, int $delta, string $reason): void
    {
        DB::transaction(function () use ($driverId, $delta, $reason) {
            DB::table('users')->where('id', $driverId)->lockForUpdate()->select('id')->first();
            DB::table('users')->where('id', $driverId)->update(['consecutive_completed' => 0]);
            self::adjust($driverId, $delta, $reason);
        });
    }

    private static function adjust(int $driverId, int $delta, string $reason, ?int $knownCurrent = null): void
    {
        $today   = now()->toDateString();
        $current = $knownCurrent ?? (int) (DB::table('users')->where('id', $driverId)->value('driver_score') ?? self::DEFAULT_SCORE);

        if ($delta > 0) {
            $row = DB::table('users')
                ->where('id', $driverId)
                ->select('daily_bonus_points', 'daily_bonus_date')
                ->first();

            $earned = ($row?->daily_bonus_date === $today) ? (int) ($row->daily_bonus_points ?? 0) : 0;
            $remaining = self::DAILY_BONUS_CAP - $earned;

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
        DB::table('users')->where('id', $driverId)->update([
            'driver_score'          => self::DEFAULT_SCORE,
            'consecutive_completed' => 0,
        ]);
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
