<?php

namespace Modules\Driver\Http\Controllers;

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
        $score  = (int) ($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE);

        $streak = DB::table('users')
            ->where('id', $driver->id)
            ->value('consecutive_completed') ?? 0;

        $data = [
            'score'     => $score,
            'max_score' => DriverScoreService::MAX_SCORE,
            'label'     => $this->labelFor($score),
            'streak'    => [
                'consecutive' => (int) $streak,
                'bonus_at'    => DriverScoreService::STREAK_THRESHOLD,
                'bonus_pts'   => DriverScoreService::SCORE_STREAK_BONUS,
            ],
            'tips' => $this->tipsFor($score),
        ];

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function history(Request $request): JsonResponse
    {
        $driver  = $request->user();
        $perPage = 10;
        $page    = max(1, (int) $request->query('page', 1));

        $total = DB::table('driver_score_logs')
            ->where('driver_id', $driver->id)
            ->count();

        $logs = DB::table('driver_score_logs')
            ->where('driver_id', $driver->id)
            ->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get(['delta', 'score_before', 'score_after', 'reason', 'created_at']);

        return response()->json([
            'success'  => true,
            'data'     => $logs,
            'has_more' => ($page * $perPage) < $total,
        ]);
    }

    private function labelFor(int $score): string
    {
        if ($score >= 80) return 'Xuất sắc';
        if ($score >= 60) return 'Tốt';
        if ($score >= 30) return 'Trung bình';
        return 'Thấp';
    }

    private function tipsFor(int $score): array
    {
        if ($score >= 80) {
            return ['Duy trì phong độ để nhận đơn ưu tiên!'];
        }
        if ($score >= 60) {
            return ['Hoàn thành liên tiếp 2 đơn để cộng thêm điểm.'];
        }
        return [
            'Tránh từ chối đơn để không bị trừ điểm.',
            'Hoàn thành đơn liên tiếp để phục hồi điểm nhanh hơn.',
        ];
    }
}
