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
        $score  = (int) (DB::table('users')->where('id', $driver->id)->value('driver_score') ?? DriverScoreService::DEFAULT_SCORE);

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
                'tips'      => DriverScoreService::tips($score),

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
            $reason === 'complete'  => 'Hoàn thành đơn',
            $reason === 'decline'   => 'Từ chối đơn',
            $reason === 'weekly_reset' => 'Reset điểm đầu tuần',
            str_starts_with($reason, 'rated_') && str_ends_with($reason, '_stars')
                => 'Khách đánh giá ' . str_replace(['rated_', '_stars'], '', $reason) . ' sao',
            default => $reason,
        ];
    }
}
