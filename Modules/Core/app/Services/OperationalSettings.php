<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cấu hình vận hành theo từng khu vực (city). Mỗi khu vực giữ trọn bộ giá trị
 * riêng trong city_operational_settings — sửa khu vực này không ảnh hưởng
 * khu vực khác. Thứ tự đọc: dòng của khu vực → bảng settings chung (giá trị
 * trước khi tách khu vực) → DEFAULTS. $cityId null (không xác định được khu
 * vực) chỉ dùng hai tầng sau.
 */
class OperationalSettings
{
    public const CACHE_KEY = 'settings:operations';

    public const CITY_TABLE = 'city_operational_settings';

    public const DEFAULTS = [
        'dispatch.max_road_distance_km' => '4',
        'driver_score.streak_milestones' => '3:1,6:2,10:4',
        'driver_score.daily_bonus_cap' => '10',
        'driver_score.max_score' => '140',
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
        'order.max_active_per_driver' => '3',
        'order.stack_max_pickup_km' => '1',
        'order.stack_max_delivery_km' => '1.5',
        // Danh sách khung giờ phụ phí đêm; "to" không tính, khung được vắt qua nửa đêm.
        'pricing.night_windows' => '[{"from":"23:00","to":"01:00","amount":5000},{"from":"01:00","to":"04:00","amount":10000}]',
        'dispatch.offer_open_seconds' => '25',
        'dispatch.offer_decision_seconds' => '30',
        'dispatch.total_timeout_minutes' => '15',
        'dispatch.retry_seconds' => '15',
        'market.enabled' => '0',
        'market.attempts_before_open' => '3',
        'market.wait_seconds_before_open' => '120',
        'market.max_pickup_distance_km' => '8',
        'market.expire_minutes' => '13',
        'market.bundle_window_minutes' => '10',
        'dispatch.score_weight' => '15',
        'dispatch.wait_weight' => '42.5',
        'dispatch.distance_weight' => '42.5',
        'dispatch.wait_cap_minutes' => '480',
        'rain_mode.auto_off_hours' => '6',
        'debt.penalty_overdue_hours' => '24',
        'wallet.low_balance_threshold' => '100000',
        'wallet.min_withdraw' => '100000',
        'referral.reward_amount' => '50000',
        'referral.min_orders' => '1',
        // Shop giới thiệu shop: điểm cho người giới thiệu, số đơn shop mới cần hoàn
        // thành, và voucher chào mừng tặng shop mới (0 = không tặng).
        'shop_referral.points' => '100',
        'shop_referral.min_orders' => '3',
        'shop_referral.welcome_amount' => '20000',
        'shop_referral.welcome_days' => '30',
    ];

    public static function dispatchMaxRoadDistanceKm(?int $cityId): float
    {
        return (float) self::value('dispatch.max_road_distance_km', $cityId);
    }

    /** @return array<int, int> Số đơn liên tiếp => điểm cộng. */
    public static function streakMilestones(?int $cityId): array
    {
        $milestones = [];

        foreach (explode(',', (string) self::value('driver_score.streak_milestones', $cityId)) as $item) {
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

    public static function dailyBonusCap(?int $cityId): int
    {
        return (int) self::value('driver_score.daily_bonus_cap', $cityId);
    }

    /** Trần điểm tài xế — điểm không vượt quá mức này. */
    public static function maxDriverScore(?int $cityId): int
    {
        return (int) self::value('driver_score.max_score', $cityId);
    }

    public static function weeklyBonusScore(?int $cityId): int
    {
        return (int) self::value('driver_score.weekly_bonus_score', $cityId);
    }

    public static function weeklyPenaltyScore(?int $cityId): int
    {
        return (int) self::value('driver_score.weekly_penalty_score', $cityId);
    }

    public static function weeklyBonusAmount(?int $cityId): int
    {
        return (int) self::value('driver_score.weekly_bonus_amount', $cityId);
    }

    public static function weeklyPenaltyAmount(?int $cityId): int
    {
        return (int) self::value('driver_score.weekly_penalty_amount', $cityId);
    }

    public static function scoreDeclinePenalty(?int $cityId): int
    {
        return (int) self::value('driver_score.decline_penalty', $cityId);
    }

    public static function scoreViewedTimeoutPenalty(?int $cityId): int
    {
        return (int) self::value('driver_score.viewed_timeout_penalty', $cityId);
    }

    public static function scoreUnviewedPenalty(?int $cityId): int
    {
        return (int) self::value('driver_score.unviewed_penalty', $cityId);
    }

    public static function unviewedWindowSize(?int $cityId): int
    {
        return (int) self::value('driver_score.unviewed_window_size', $cityId);
    }

    public static function unviewedLimit(?int $cityId): int
    {
        return (int) self::value('driver_score.unviewed_limit', $cityId);
    }

    /** @return array{normal: float, reduced: float, mid: float, low: float} */
    public static function shiftOnlineThresholds(?int $cityId): array
    {
        return [
            'normal' => (int) self::value('driver_score.shift_normal_min_percent', $cityId) / 100,
            'reduced' => (int) self::value('driver_score.shift_reduced_min_percent', $cityId) / 100,
            'mid' => (int) self::value('driver_score.shift_mid_min_percent', $cityId) / 100,
            'low' => (int) self::value('driver_score.shift_low_min_percent', $cityId) / 100,
        ];
    }

    /** @return array{reduced: int, mid: int, low: int, critical: int} */
    public static function shiftOnlinePenalties(?int $cityId): array
    {
        return [
            'reduced' => (int) self::value('driver_score.shift_reduced_penalty', $cityId),
            'mid' => (int) self::value('driver_score.shift_mid_penalty', $cityId),
            'low' => (int) self::value('driver_score.shift_low_penalty', $cityId),
            'critical' => (int) self::value('driver_score.shift_critical_penalty', $cityId),
        ];
    }

    public static function referralRewardAmount(?int $cityId): int
    {
        return max(0, (int) self::value('referral.reward_amount', $cityId));
    }

    public static function referralMinOrders(?int $cityId): int
    {
        return max(1, (int) self::value('referral.min_orders', $cityId));
    }

    public static function shopReferralPoints(?int $cityId): int
    {
        return max(0, (int) self::value('shop_referral.points', $cityId));
    }

    public static function shopReferralMinOrders(?int $cityId): int
    {
        return max(1, (int) self::value('shop_referral.min_orders', $cityId));
    }

    public static function shopReferralWelcomeAmount(?int $cityId): int
    {
        return max(0, (int) self::value('shop_referral.welcome_amount', $cityId));
    }

    public static function shopReferralWelcomeDays(?int $cityId): int
    {
        return max(1, (int) self::value('shop_referral.welcome_days', $cityId));
    }

    public static function rainBonusAmount(?int $cityId): int
    {
        return (int) self::value('order.rain_bonus_amount', $cityId);
    }

    public static function completionRadiusKm(?int $cityId): float
    {
        return (int) self::value('order.completion_radius_meters', $cityId) / 1000;
    }

    public static function completionRadiusMeters(?int $cityId): int
    {
        return (int) self::value('order.completion_radius_meters', $cityId);
    }

    public static function autoCompleteGraceMinutes(?int $cityId): int
    {
        return (int) self::value('order.auto_complete_grace_minutes', $cityId);
    }

    public static function maxOrderDistanceKm(?int $cityId): float
    {
        return (float) self::value('order.max_distance_km', $cityId);
    }

    public static function ratingWindowHours(?int $cityId): int
    {
        return (int) self::value('order.rating_window_hours', $cityId);
    }

    public static function delayedReminderMinutes(?int $cityId): int
    {
        return (int) self::value('order.delayed_reminder_minutes', $cityId);
    }

    public static function maxActiveOrdersPerDriver(?int $cityId): int
    {
        return (int) self::value('order.max_active_per_driver', $cityId);
    }

    public static function stackMaxPickupKm(?int $cityId): float
    {
        return (float) self::value('order.stack_max_pickup_km', $cityId);
    }

    public static function stackMaxDeliveryKm(?int $cityId): float
    {
        return (float) self::value('order.stack_max_delivery_km', $cityId);
    }

    public static function nightSurcharge(?int $cityId, ?\DateTimeInterface $at = null): int
    {
        $at ??= now();
        $minute = (int) $at->format('G') * 60 + (int) $at->format('i');

        foreach (self::nightWindows($cityId) as $window) {
            if (self::minuteInWindow($minute, self::toMinute($window['from']), self::toMinute($window['to']))) {
                return $window['amount'];
            }
        }

        return 0;
    }

    /** @return list<array{from: string, to: string, amount: int}> */
    public static function nightWindows(?int $cityId): array
    {
        $windows = self::parseNightWindows(self::value('pricing.night_windows', $cityId));

        // Chuỗi hỏng (sửa tay DB) thì dùng khung mặc định, không bỏ mất phụ phí.
        return $windows ?? self::parseNightWindows(self::DEFAULTS['pricing.night_windows']);
    }

    /** @return list<array{from: string, to: string, amount: int}>|null */
    public static function parseNightWindows(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }

        $windows = [];
        foreach ($decoded as $window) {
            if (! is_array($window)
                || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($window['from'] ?? ''))
                || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($window['to'] ?? ''))
                || ! is_numeric($window['amount'] ?? null)) {
                return null;
            }
            $windows[] = ['from' => $window['from'], 'to' => $window['to'], 'amount' => (int) $window['amount']];
        }

        return $windows;
    }

    /** Phút trong ngày (0–1439) từ chuỗi "HH:MM". */
    public static function toMinute(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }

    /** Khung [from, to) — from > to nghĩa là vắt qua nửa đêm. */
    public static function minuteInWindow(int $minute, int $from, int $to): bool
    {
        return $from < $to
            ? $minute >= $from && $minute < $to
            : $minute >= $from || $minute < $to;
    }

    public static function offerOpenSeconds(?int $cityId): int
    {
        return (int) self::value('dispatch.offer_open_seconds', $cityId);
    }

    public static function offerDecisionSeconds(?int $cityId): int
    {
        return (int) self::value('dispatch.offer_decision_seconds', $cityId);
    }

    public static function dispatchTimeoutMinutes(?int $cityId): int
    {
        return (int) self::value('dispatch.total_timeout_minutes', $cityId);
    }

    public static function dispatchRetrySeconds(?int $cityId): int
    {
        return (int) self::value('dispatch.retry_seconds', $cityId);
    }

    public static function orderMarketEnabled(?int $cityId): bool { return (int) self::value('market.enabled', $cityId) === 1; }
    public static function marketAttemptsBeforeOpen(?int $cityId): int { return (int) self::value('market.attempts_before_open', $cityId); }
    public static function marketWaitSecondsBeforeOpen(?int $cityId): int { return (int) self::value('market.wait_seconds_before_open', $cityId); }
    public static function marketMaxPickupDistanceKm(?int $cityId): float { return (float) self::value('market.max_pickup_distance_km', $cityId); }
    public static function marketExpireMinutes(?int $cityId): int { return (int) self::value('market.expire_minutes', $cityId); }
    public static function marketBundleWindowMinutes(?int $cityId): int { return (int) self::value('market.bundle_window_minutes', $cityId); }

    /** @return array{score: float, wait: float, distance: float} */
    public static function dispatchWeights(?int $cityId): array
    {
        return [
            'score' => (float) self::value('dispatch.score_weight', $cityId),
            'wait' => (float) self::value('dispatch.wait_weight', $cityId),
            'distance' => (float) self::value('dispatch.distance_weight', $cityId),
        ];
    }

    public static function dispatchWaitCapMinutes(?int $cityId): int
    {
        return (int) self::value('dispatch.wait_cap_minutes', $cityId);
    }

    public static function rainModeAutoOffHours(?int $cityId): int
    {
        return (int) self::value('rain_mode.auto_off_hours', $cityId);
    }

    public static function penaltyDebtOverdueHours(?int $cityId): int
    {
        return (int) self::value('debt.penalty_overdue_hours', $cityId);
    }

    /** Số tiền tối thiểu một yêu cầu rút. */
    public static function minWithdrawAmount(?int $cityId): int
    {
        return max(1000, (int) self::value('wallet.min_withdraw', $cityId));
    }

    public static function lowWalletBalanceThreshold(?int $cityId): int
    {
        return (int) self::value('wallet.low_balance_threshold', $cityId);
    }

    /** Giữ bản cấu hình trong bộ nhớ process để vòng chấm điểm tài xế không đọc cache liên tục. */
    private const MEMO_SECONDS = 15;

    /** @var array<string, array{values: array<string, string>, expires: int}> */
    private static array $memo = [];

    public static function value(string $key, ?int $cityId): string
    {
        return (string) (self::all($cityId)[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    /** @return array<string, string> Trọn bộ giá trị đã hợp nhất cho khu vực. */
    public static function all(?int $cityId): array
    {
        $memoKey = (string) ($cityId ?? 'global');
        if (isset(self::$memo[$memoKey]) && time() < self::$memo[$memoKey]['expires']) {
            return self::$memo[$memoKey]['values'];
        }

        try {
            $values = array_merge(self::DEFAULTS, Cache::remember(self::CACHE_KEY, now()->addMinute(), fn () => DB::table('settings')
                ->whereIn('key', array_keys(self::DEFAULTS))
                ->pluck('value', 'key')
                ->all()));
        } catch (\Throwable) {
            // Cho phép command/test khởi động an toàn trước khi migration settings chạy.
            return self::DEFAULTS;
        }

        if ($cityId !== null) {
            try {
                $values = array_merge($values, Cache::remember(self::cityCacheKey($cityId), now()->addMinute(), fn () => DB::table(self::CITY_TABLE)
                    ->where('city_id', $cityId)
                    ->whereIn('key', array_keys(self::DEFAULTS))
                    ->pluck('value', 'key')
                    ->all()));
            } catch (\Throwable) {
                // Bảng khu vực chưa migrate (vài giây lúc deploy): dùng giá trị
                // chung đang áp dụng, không rơi về DEFAULTS; không memo để lần
                // sau đọc lại ngay khi bảng đã có.
                return $values;
            }
        }

        // Worker chạy lâu (queue:work) sẽ nhận giá trị mới tối đa sau MEMO_SECONDS.
        self::$memo[$memoKey] = ['values' => $values, 'expires' => time() + self::MEMO_SECONDS];

        return $values;
    }

    public static function flush(?int $cityId = null): void
    {
        self::$memo = [];
        Cache::forget(self::CACHE_KEY);
        if ($cityId !== null) {
            Cache::forget(self::cityCacheKey($cityId));
        }
    }

    /** @param array<string, int|float|string> $values */
    public static function put(array $values, int $cityId): void
    {
        $now = now();
        $rows = [];

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            $rows[] = [
                'city_id' => $cityId,
                'key' => $key,
                'value' => (string) $value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table(self::CITY_TABLE)->upsert($rows, ['city_id', 'key'], ['value', 'updated_at']);
        self::flush($cityId);
    }

    /**
     * Tạo bộ cấu hình riêng cho khu vực từ giá trị chung hiện hành. Không ghi
     * đè dòng đã có — gọi lại an toàn.
     */
    public static function initializeCity(int $cityId): void
    {
        // Khu vực tạo trước khi migration bảng này chạy được migration khởi tạo sau.
        if (! Schema::hasTable(self::CITY_TABLE)) {
            return;
        }

        $now = now();
        $rows = [];
        foreach (self::all(null) as $key => $value) {
            $rows[] = ['city_id' => $cityId, 'key' => $key, 'value' => (string) $value, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table(self::CITY_TABLE)->insertOrIgnore($rows);
        self::flush($cityId);
    }

    public static function lastUpdatedAt(int $cityId): ?string
    {
        return DB::table(self::CITY_TABLE)->where('city_id', $cityId)->max('updated_at');
    }

    private static function cityCacheKey(int $cityId): string
    {
        return self::CACHE_KEY.':city:'.$cityId;
    }
}
