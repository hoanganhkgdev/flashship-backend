<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OperationalSettings
{
    public const CACHE_KEY = 'settings:operations';

    public const DEFAULTS = [
        'dispatch.max_road_distance_km' => '4',
        'driver_score.streak_milestones' => '3:1,6:2,10:4',
        'driver_score.daily_bonus_cap' => '10',
        'driver_score.weekly_bonus_score' => '140',
        'driver_score.weekly_penalty_score' => '70',
        'driver_score.weekly_bonus_amount' => '200000',
        'driver_score.weekly_penalty_amount' => '100000',
        'driver_score.decline_penalty' => '-2',
        'driver_score.viewed_timeout_penalty' => '-2',
        'driver_score.unviewed_penalty' => '-2',
        'driver_score.unviewed_window_size' => '5',
        'driver_score.unviewed_limit' => '3',
        'driver_score.shift_normal_min_percent' => '85',
        'driver_score.shift_reduced_min_percent' => '70',
        'driver_score.shift_mid_min_percent' => '60',
        'driver_score.shift_low_min_percent' => '50',
        'driver_score.shift_reduced_penalty' => '-3',
        'driver_score.shift_mid_penalty' => '-5',
        'driver_score.shift_low_penalty' => '-10',
        'driver_score.shift_critical_penalty' => '-15',
        'order.rain_bonus_amount' => '5000',
        'order.completion_radius_meters' => '300',
        'order.auto_complete_grace_minutes' => '3',
        'order.max_distance_km' => '15',
        'order.rating_window_hours' => '24',
        'order.delayed_reminder_minutes' => '15',
        'order.max_active_per_driver' => '2',
        'order.stack_max_pickup_km' => '1',
        'order.stack_max_delivery_km' => '1.5',
        'pricing.night_23_00_amount' => '5000',
        'pricing.night_01_03_amount' => '10000',
        'dispatch.offer_open_seconds' => '25',
        'dispatch.offer_decision_seconds' => '30',
        'dispatch.total_timeout_minutes' => '15',
        'dispatch.retry_seconds' => '15',
        'dispatch.score_weight' => '15',
        'dispatch.wait_weight' => '42.5',
        'dispatch.distance_weight' => '42.5',
        'dispatch.wait_cap_minutes' => '480',
        'rain_mode.auto_off_hours' => '6',
        'debt.penalty_overdue_hours' => '24',
        'wallet.low_balance_threshold' => '100000',
    ];

    public static function dispatchMaxRoadDistanceKm(): float
    {
        return (float) self::value('dispatch.max_road_distance_km');
    }

    /** @return array<int, int> Số đơn liên tiếp => điểm cộng. */
    public static function streakMilestones(): array
    {
        $milestones = [];

        foreach (explode(',', (string) self::value('driver_score.streak_milestones')) as $item) {
            [$orders, $points] = array_pad(explode(':', trim($item), 2), 2, null);
            if (is_numeric($orders) && is_numeric($points) && (int) $orders > 0 && (int) $points > 0) {
                $milestones[(int) $orders] = (int) $points;
            }
        }

        if ($milestones === []) {
            return [3 => 1, 6 => 2, 10 => 4];
        }

        ksort($milestones);

        return $milestones;
    }

    public static function dailyBonusCap(): int
    {
        return (int) self::value('driver_score.daily_bonus_cap');
    }

    public static function weeklyBonusScore(): int
    {
        return (int) self::value('driver_score.weekly_bonus_score');
    }

    public static function weeklyPenaltyScore(): int
    {
        return (int) self::value('driver_score.weekly_penalty_score');
    }

    public static function weeklyBonusAmount(): int
    {
        return (int) self::value('driver_score.weekly_bonus_amount');
    }

    public static function weeklyPenaltyAmount(): int
    {
        return (int) self::value('driver_score.weekly_penalty_amount');
    }

    public static function scoreDeclinePenalty(): int
    {
        return (int) self::value('driver_score.decline_penalty');
    }

    public static function scoreViewedTimeoutPenalty(): int
    {
        return (int) self::value('driver_score.viewed_timeout_penalty');
    }

    public static function scoreUnviewedPenalty(): int
    {
        return (int) self::value('driver_score.unviewed_penalty');
    }

    public static function unviewedWindowSize(): int
    {
        return (int) self::value('driver_score.unviewed_window_size');
    }

    public static function unviewedLimit(): int
    {
        return (int) self::value('driver_score.unviewed_limit');
    }

    /** @return array{normal: float, reduced: float, mid: float, low: float} */
    public static function shiftOnlineThresholds(): array
    {
        return [
            'normal' => (int) self::value('driver_score.shift_normal_min_percent') / 100,
            'reduced' => (int) self::value('driver_score.shift_reduced_min_percent') / 100,
            'mid' => (int) self::value('driver_score.shift_mid_min_percent') / 100,
            'low' => (int) self::value('driver_score.shift_low_min_percent') / 100,
        ];
    }

    /** @return array{reduced: int, mid: int, low: int, critical: int} */
    public static function shiftOnlinePenalties(): array
    {
        return [
            'reduced' => (int) self::value('driver_score.shift_reduced_penalty'),
            'mid' => (int) self::value('driver_score.shift_mid_penalty'),
            'low' => (int) self::value('driver_score.shift_low_penalty'),
            'critical' => (int) self::value('driver_score.shift_critical_penalty'),
        ];
    }

    public static function rainBonusAmount(): int
    {
        return (int) self::value('order.rain_bonus_amount');
    }

    public static function completionRadiusKm(): float
    {
        return (int) self::value('order.completion_radius_meters') / 1000;
    }

    public static function completionRadiusMeters(): int
    {
        return (int) self::value('order.completion_radius_meters');
    }

    public static function autoCompleteGraceMinutes(): int
    {
        return (int) self::value('order.auto_complete_grace_minutes');
    }

    public static function maxOrderDistanceKm(): float
    {
        return (float) self::value('order.max_distance_km');
    }

    public static function ratingWindowHours(): int
    {
        return (int) self::value('order.rating_window_hours');
    }

    public static function delayedReminderMinutes(): int
    {
        return (int) self::value('order.delayed_reminder_minutes');
    }

    public static function maxActiveOrdersPerDriver(): int
    {
        return (int) self::value('order.max_active_per_driver');
    }

    public static function stackMaxPickupKm(): float
    {
        return (float) self::value('order.stack_max_pickup_km');
    }

    public static function stackMaxDeliveryKm(): float
    {
        return (float) self::value('order.stack_max_delivery_km');
    }

    public static function nightSurcharge(?int $hour = null): int
    {
        $hour ??= (int) now()->format('G');

        return match (true) {
            $hour === 23 || $hour === 0 => (int) self::value('pricing.night_23_00_amount'),
            $hour >= 1 && $hour <= 3 => (int) self::value('pricing.night_01_03_amount'),
            default => 0,
        };
    }

    public static function offerOpenSeconds(): int
    {
        return (int) self::value('dispatch.offer_open_seconds');
    }

    public static function offerDecisionSeconds(): int
    {
        return (int) self::value('dispatch.offer_decision_seconds');
    }

    public static function dispatchTimeoutMinutes(): int
    {
        return (int) self::value('dispatch.total_timeout_minutes');
    }

    public static function dispatchRetrySeconds(): int
    {
        return (int) self::value('dispatch.retry_seconds');
    }

    /** @return array{score: float, wait: float, distance: float} */
    public static function dispatchWeights(): array
    {
        return [
            'score' => (float) self::value('dispatch.score_weight'),
            'wait' => (float) self::value('dispatch.wait_weight'),
            'distance' => (float) self::value('dispatch.distance_weight'),
        ];
    }

    public static function dispatchWaitCapMinutes(): int
    {
        return (int) self::value('dispatch.wait_cap_minutes');
    }

    public static function rainModeAutoOffHours(): int
    {
        return (int) self::value('rain_mode.auto_off_hours');
    }

    public static function penaltyDebtOverdueHours(): int
    {
        return (int) self::value('debt.penalty_overdue_hours');
    }

    public static function lowWalletBalanceThreshold(): int
    {
        return (int) self::value('wallet.low_balance_threshold');
    }

    /** Giữ bản cấu hình trong bộ nhớ process để vòng chấm điểm tài xế không đọc cache liên tục. */
    private const MEMO_SECONDS = 15;

    /** @var array<string, string>|null */
    private static ?array $memo = null;

    private static int $memoExpiresAt = 0;

    public static function value(string $key): string
    {
        $default = self::DEFAULTS[$key] ?? '';

        if (self::$memo !== null && time() < self::$memoExpiresAt) {
            return (string) (self::$memo[$key] ?? $default);
        }

        try {
            $values = Cache::remember(self::CACHE_KEY, now()->addMinute(), fn () => DB::table('settings')
                ->whereIn('key', array_keys(self::DEFAULTS))
                ->pluck('value', 'key')
                ->all());
        } catch (\Throwable) {
            // Cho phép command/test khởi động an toàn trước khi migration settings chạy.
            return $default;
        }

        // Worker chạy lâu (queue:work) sẽ nhận giá trị mới tối đa sau MEMO_SECONDS.
        self::$memo = $values;
        self::$memoExpiresAt = time() + self::MEMO_SECONDS;

        return (string) ($values[$key] ?? $default);
    }

    public static function flush(): void
    {
        self::$memo = null;
        self::$memoExpiresAt = 0;
        Cache::forget(self::CACHE_KEY);
    }

    /** @param array<string, int|float|string> $values */
    public static function put(array $values): void
    {
        $now = now();
        $rows = [];

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'value' => (string) $value,
                'group' => 'operations',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('settings')->upsert($rows, ['key'], ['value', 'group', 'updated_at']);
        self::flush();
    }
}
