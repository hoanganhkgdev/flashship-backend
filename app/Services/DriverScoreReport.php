<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Services\DriverScoreService;

/**
 * Số liệu cho trang Điểm tài xế. Công thức dự kiến chốt tuần PHẢI giống
 * WeeklyScoreCommand (chỉ tài xế status=1; điểm >= mốc thưởng → thưởng, điểm <= mốc phạt → phạt).
 */
class DriverScoreReport
{
    /** Các reason là "đặt lại điểm", không phải hành vi của tài xế. */
    public const RESET_REASONS = ['weekly_reset', 'manual_reset'];

    /** Biến động thực (đã tính trần/sàn). Cột điểm là unsigned nên phải ép kiểu trước khi trừ. */
    public const REAL_CHANGE = 'CAST(score_after AS SIGNED) - CAST(score_before AS SIGNED)';

    /** Kết quả chốt tuần nếu chốt ngay bây giờ cho tài xế đang hoạt động của khu vực. */
    public static function projectedOutcome(int $score, ?int $cityId): ?string
    {
        if ($score >= DriverScoreService::weeklyBonusScore($cityId)) {
            return 'bonus';
        }

        return $score <= DriverScoreService::weeklyPenaltyScore($cityId) ? 'penalty' : null;
    }

    /** @return array{active:int,avg:float,bonus:int,penalty:int,bonusMoney:int,penaltyMoney:int,net:int,bonusScore:int,penaltyScore:int,bonusAmount:int,penaltyAmount:int} */
    public static function projection(?int $cityId): array
    {
        $scores = DB::table('users')->where('user_type', 'driver')->where('status', 1)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->pluck('driver_score')
            ->map(fn ($s) => (int) ($s ?? DriverScoreService::DEFAULT_SCORE));

        $bonusScore = DriverScoreService::weeklyBonusScore($cityId);
        $penaltyScore = DriverScoreService::weeklyPenaltyScore($cityId);
        $bonusAmount = DriverScoreService::weeklyBonusAmount($cityId);
        $penaltyAmount = DriverScoreService::weeklyPenaltyAmount($cityId);

        $bonus = $scores->filter(fn ($s) => $s >= $bonusScore)->count();
        $penalty = $scores->filter(fn ($s) => $s < $bonusScore && $s <= $penaltyScore)->count();

        return [
            'active' => $scores->count(),
            'avg' => $scores->isEmpty() ? 0.0 : round($scores->avg(), 1),
            'bonus' => $bonus,
            'penalty' => $penalty,
            'bonusMoney' => $bonus * $bonusAmount,
            'penaltyMoney' => $penalty * $penaltyAmount,
            'net' => $penalty * $penaltyAmount - $bonus * $bonusAmount,
            'bonusScore' => $bonusScore,
            'penaltyScore' => $penaltyScore,
            'bonusAmount' => $bonusAmount,
            'penaltyAmount' => $penaltyAmount,
        ];
    }

    /** Điểm thực sự bị trừ theo lý do (bỏ qua các lần đặt lại điểm). */
    public static function lossByReason(?int $cityId, int $days = 30): Collection
    {
        return DB::table('driver_score_logs as l')
            ->join('users as u', 'u.id', '=', 'l.driver_id')
            ->where('l.created_at', '>=', now()->subDays($days))
            ->whereNotIn('l.reason', self::RESET_REASONS)
            ->whereColumn('l.score_after', '<', 'l.score_before')
            ->when($cityId, fn ($q) => $q->where('u.city_id', $cityId))
            ->selectRaw('l.reason, SUM(l.score_before - l.score_after) AS lost, COUNT(*) AS times, COUNT(DISTINCT l.driver_id) AS drivers')
            ->groupBy('l.reason')
            ->orderByDesc('lost')
            ->get();
    }

    /** Lịch sử chốt thưởng/phạt các tuần gần nhất của khu vực. */
    public static function settlementHistory(?int $cityId, int $weeks = 6): Collection
    {
        return DB::table('driver_score_settlements as s')
            ->join('users as u', 'u.id', '=', 's.driver_id')
            ->when($cityId, fn ($q) => $q->where('u.city_id', $cityId))
            ->selectRaw("s.week_start, s.week_end,
                SUM(s.type = 'bonus') AS bonus_count, SUM(CASE WHEN s.type = 'bonus' THEN s.amount ELSE 0 END) AS bonus_money,
                SUM(s.type = 'penalty') AS penalty_count, SUM(CASE WHEN s.type = 'penalty' THEN s.amount ELSE 0 END) AS penalty_money")
            ->groupBy('s.week_start', 's.week_end')
            ->orderByDesc('s.week_start')
            ->limit($weeks)
            ->get();
    }

    /** Điểm cuối mỗi ngày trong N ngày (suy từ nhật ký; ngày không có biến động giữ điểm trước đó). */
    public static function scoreSeries(int $driverId, int $currentScore, int $days = 30): array
    {
        $since = Carbon::today()->subDays($days - 1);

        $lastPerDay = DB::table('driver_score_logs')
            ->where('driver_id', $driverId)->where('created_at', '>=', $since)
            ->orderBy('id')
            ->get(['score_after', 'created_at'])
            ->groupBy(fn ($r) => Carbon::parse($r->created_at)->toDateString())
            ->map(fn ($g) => (int) $g->last()->score_after);

        $carry = DB::table('driver_score_logs')->where('driver_id', $driverId)->where('created_at', '<', $since)
            ->orderByDesc('id')->value('score_after');
        $carry = $carry !== null ? (int) $carry : ($lastPerDay->isEmpty() ? $currentScore : (int) DB::table('driver_score_logs')
            ->where('driver_id', $driverId)->where('created_at', '>=', $since)->orderBy('id')->value('score_before'));

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $since->copy()->addDays($i);
            $carry = $lastPerDay[$day->toDateString()] ?? $carry;
            $series[] = ['label' => $day->format('d/m'), 'score' => $carry];
        }

        return $series;
    }

    /** Điểm cộng/trừ thực của một tài xế theo lý do trong N ngày. */
    public static function driverBreakdown(int $driverId, int $days = 30): Collection
    {
        return DB::table('driver_score_logs')
            ->where('driver_id', $driverId)->where('created_at', '>=', now()->subDays($days))
            ->whereNotIn('reason', self::RESET_REASONS)
            ->where('delta', '<>', 0)
            ->selectRaw('reason, SUM('.self::REAL_CHANGE.') AS net, COUNT(*) AS times')
            ->groupBy('reason')
            ->havingRaw('net <> 0')
            ->orderBy('net')
            ->get();
    }
}
