<?php

namespace Modules\Driver\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Services\DriverScoreService;

class ScoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $driver = $request->user();
        $user   = DB::table('users')->where('id', $driver->id)
            ->select(
                'driver_score', 'consecutive_completed',
                'daily_bonus_points', 'daily_bonus_date',
                'daily_online_seconds', 'daily_online_date',
                'is_online', 'online_since'
            )->first();

        $score  = (int) ($user->driver_score ?? DriverScoreService::DEFAULT_SCORE);
        $streak = (int) ($user->consecutive_completed ?? 0);

        // Streak: mốc tiếp theo
        $nextMilestone = null;
        $nextBonus     = null;
        foreach (DriverScoreService::STREAK_MILESTONES as $milestone => $bonus) {
            if ($streak < $milestone) {
                $nextMilestone = $milestone;
                $nextBonus     = $bonus;
                break;
            }
        }

        // Daily bonus đã dùng hôm nay
        $today      = now()->toDateString();
        $bonusToday = ($user->daily_bonus_date === $today) ? (int) $user->daily_bonus_points : 0;

        // Online hôm nay (giây) — cộng thêm phần đang chạy nếu đang online
        $onlineSecs = ($user->daily_online_date === $today) ? (int) $user->daily_online_seconds : 0;
        if ($user->is_online && $user->online_since) {
            $since = Carbon::parse($user->online_since);
            if ($since->toDateString() === $today) {
                $onlineSecs += (int) $since->diffInSeconds(now());
            }
        }

        // Weekly settlement pending cho tuần hiện tại
        $weekStart  = Carbon::now()->startOfWeek()->toDateString();
        $settlement = DB::table('driver_score_settlements')
            ->where('driver_id', $driver->id)
            ->where('week_start', $weekStart)
            ->select('type', 'amount', 'status')
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'score'     => $score,
                'min_score' => DriverScoreService::MIN_SCORE,
                'max_score' => DriverScoreService::MAX_SCORE,
                'label'     => DriverScoreService::label($score),
                'tips'      => DriverScoreService::tips($score, $streak),

                'week' => [
                    'bonus_at'       => DriverScoreService::WEEKLY_BONUS_SCORE,
                    'penalty_at'     => DriverScoreService::WEEKLY_PENALTY_SCORE,
                    'bonus_amount'   => DriverScoreService::WEEKLY_BONUS_AMOUNT,
                    'penalty_amount' => DriverScoreService::WEEKLY_PENALTY_AMOUNT,
                    'week_start'     => $weekStart,
                    'settlement'     => $settlement ? [
                        'type'   => $settlement->type,
                        'amount' => (int) $settlement->amount,
                        'status' => $settlement->status,
                    ] : null,
                ],

                'streak' => [
                    'consecutive'    => $streak,
                    'milestones'     => DriverScoreService::STREAK_MILESTONES,
                    'next_milestone' => $nextMilestone,
                    'next_bonus_pts' => $nextBonus,
                ],

                'daily' => [
                    'bonus_points_today'   => $bonusToday,
                    'bonus_cap'            => DriverScoreService::DAILY_BONUS_CAP,
                    'bonus_remaining'      => max(0, DriverScoreService::DAILY_BONUS_CAP - $bonusToday),
                    'online_seconds_today' => $onlineSecs,
                    'online_hours_today'   => round($onlineSecs / 3600, 1),
                    'min_online_seconds'   => DriverScoreService::MIN_ONLINE_SECONDS,
                ],
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $driver  = $request->user();
        $perPage = 15;
        $page    = max(1, (int) $request->query('page', 1));

        $total = DB::table('driver_score_logs')
            ->where('driver_id', $driver->id)
            ->count();

        $logs = DB::table('driver_score_logs')
            ->where('driver_id', $driver->id)
            ->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get(['delta', 'score_before', 'score_after', 'reason', 'created_at'])
            ->map(fn ($log) => [
                'delta'        => (int) $log->delta,
                'score_before' => (int) $log->score_before,
                'score_after'  => (int) $log->score_after,
                'reason'       => $log->reason,
                'label'        => self::reasonLabel($log->reason),
                'created_at'   => $log->created_at,
            ]);

        return response()->json([
            'success'  => true,
            'data'     => $logs,
            'total'    => $total,
            'has_more' => ($page * $perPage) < $total,
        ]);
    }

    private static function reasonLabel(string $reason): string
    {
        return match (true) {
            $reason === 'decline'         => 'Từ chối đơn',
            $reason === 'timeout'         => 'Hết giờ nhận đơn',
            $reason === 'streak_3'        => 'Hoàn thành 3 đơn liên tiếp',
            $reason === 'streak_6'        => 'Hoàn thành 6 đơn liên tiếp',
            $reason === 'streak_10'       => 'Hoàn thành 10 đơn liên tiếp',
            $reason === 'inactivity_1d'   => 'Không hoạt động 1 ngày',
            $reason === 'inactivity_2d'   => 'Không hoạt động 2+ ngày',
            $reason === 'online_below_8h' => 'Online dưới 8 giờ/ngày',
            $reason === 'weekly_reset'    => 'Reset điểm đầu tuần',
            str_starts_with($reason, 'rated_') && str_ends_with($reason, '_stars')
                => 'Khách đánh giá ' . str_replace(['rated_', '_stars'], '', $reason) . ' sao',
            default => $reason,
        };
    }
}
