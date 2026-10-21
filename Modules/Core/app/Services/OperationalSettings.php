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
        'order.rain_bonus_amount' => '5000',
        'shift.offline_limit_minutes' => '30',
        'shift.offline_penalty_amount' => '50000',
        'order.completion_radius_meters' => '300',
        'order.auto_complete_grace_minutes' => '3',
        'order.max_distance_km' => '15',
        'order.rating_window_hours' => '24',
        'order.delayed_reminder_minutes' => '15',
        // Danh sách khung giờ phụ phí đêm; "to" không tính, khung được vắt qua nửa đêm.
        'pricing.night_windows' => '[{"from":"23:00","to":"01:00","amount":5000},{"from":"01:00","to":"04:00","amount":10000}]',
        'rain_mode.auto_off_hours' => '6',
        // 1 = khu vực có dùng công nợ; 0 = tắt (không tạo công nợ mới, không chặn tài xế vì nợ quá hạn).
        'debt.enabled' => '1',
        // Cách thu công nợ định kỳ của khu vực: weekly (phí tuần theo City::weekly_fee),
        // daily_percent (% phí ship các đơn hoàn thành trong ngày) hoặc none.
        'debt.collection_mode' => 'weekly',
        'debt.daily_percent' => '10',
        'debt.daily_overdue_hours' => '24',
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

    /** Số phút tối đa tài xế được Offline trong một ca. */
    public static function shiftOfflineLimitMinutes(?int $cityId): int
    {
        return max(0, (int) self::value('shift.offline_limit_minutes', $cityId));
    }

    /** Tiền phạt (đ) khi Offline quá giới hạn trong một ca. */
    public static function shiftOfflinePenaltyAmount(?int $cityId): int
    {
        return max(0, (int) self::value('shift.offline_penalty_amount', $cityId));
    }

    /** Số đơn tối đa một tài xế được giữ cùng lúc — cố định cho mọi khu vực. */
    public const MAX_ACTIVE_ORDERS_PER_DRIVER = 3;

    public static function maxActiveOrdersPerDriver(?int $cityId = null): int
    {
        return self::MAX_ACTIVE_ORDERS_PER_DRIVER;
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

    public static function rainModeAutoOffHours(?int $cityId): int
    {
        return (int) self::value('rain_mode.auto_off_hours', $cityId);
    }

    public static function debtEnabled(?int $cityId): bool
    {
        return self::value('debt.enabled', $cityId) !== '0';
    }

    public static function debtCollectionMode(?int $cityId): string
    {
        $mode = self::value('debt.collection_mode', $cityId);

        return in_array($mode, ['weekly', 'daily_percent', 'none'], true) ? $mode : 'weekly';
    }

    /** % phí ship mỗi ngày thu từ tài xế (0–100). */
    public static function dailyCommissionPercent(?int $cityId): float
    {
        return max(0, min(100, (float) self::value('debt.daily_percent', $cityId)));
    }

    public static function dailyCommissionOverdueHours(?int $cityId): int
    {
        return max(1, (int) self::value('debt.daily_overdue_hours', $cityId));
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

    /** Giữ bản cấu hình trong bộ nhớ process để các tác vụ chạy nền không đọc cache liên tục. */
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
