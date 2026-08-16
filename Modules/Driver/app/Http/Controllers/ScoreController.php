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
        $row    = DB::table('users')
            ->where('id', $driver->id)
            ->select('driver_score', 'consecutive_completed')
            ->first();

        $score  = (int) ($row->driver_score          ?? DriverScoreService::DEFAULT_SCORE);
        $streak = (int) ($row->consecutive_completed ?? 0);

        $weekStart  = Carbon::now()->startOfWeek()->toDateString();
        $settlement = DB::table('driver_score_settlements')
            ->where('driver_id', $driver->id)
            ->where('week_start', $weekStart)
            ->select('type', 'amount', 'status')
            ->first();

        // Mốc streak tiếp theo (để hiển thị cho tài xế biết còn cần bao nhiêu đơn)
        $nextMilestone = null;
        foreach (DriverScoreService::STREAK_MILESTONES as $at => $bonus) {
            if ($streak < $at) {
                $nextMilestone = ['at' => $at, 'bonus' => $bonus, 'remaining' => $at - $streak];
                break;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'score'     => $score,
                'min_score' => DriverScoreService::MIN_SCORE,
                'max_score' => DriverScoreService::MAX_SCORE,
                'label'     => DriverScoreService::label($score),
                'tips'      => DriverScoreService::tips($score),

                'streak' => [
                    'count'          => $streak,
                    'next_milestone' => $nextMilestone,
                ],

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

    /**
     * Map reason → nhãn tiếng Việt hiển thị cho tài xế. Tính từ reason lưu
     * trong DB mỗi lần gọi (không lưu label) nên sửa hàm này là fix ngay cả
     * lịch sử cũ, không cần migrate dữ liệu.
     *
     * Bao gồm cả các reason cũ từ trước khi đổi tên (online_below_8h,
     * inactivity_1d/2d/14d...) — soát production xác nhận ~48% tổng số dòng
     * driver_score_logs rơi vào nhánh default (hiện chuỗi thô "complete",
     * "online_below_8h"...) trước khi có map này, riêng 'complete' một mình
     * đã chiếm hơn 1/3 tổng số dòng vì mọi đơn hoàn thành không trúng mốc
     * streak đều ghi log reason='complete'.
     */
    private static function reasonLabel(string $reason): string
    {
        return match (true) {
            $reason === 'decline'          => 'Từ chối đơn',
            $reason === 'timeout' || $reason === 'viewed_timeout'
                => 'Để đơn trôi qua',
            $reason === 'complete'         => 'Hoàn thành đơn',
            $reason === 'weekly_reset'     => 'Reset điểm đầu tuần',
            $reason === 'shift_violation'  => 'Vi phạm ca làm việc',
            // 5 mốc hiện hành (onShiftOnlineRate) — % thời gian online/ca.
            $reason === 'shift_online_normal'   => 'Online 85–100% ca',
            $reason === 'shift_online_reduced'  => 'Online 70–84% ca',
            $reason === 'shift_online_mid'      => 'Online 60–69% ca',
            $reason === 'shift_online_low'      => 'Online 50–59% ca',
            $reason === 'shift_online_critical' => 'Online dưới 50% ca',
            // Mốc cũ trước đợt đổi ngưỡng ở trên — giữ lại chỉ để hiển thị
            // đúng cho các dòng lịch sử đã ghi từ trước, không còn được tạo
            // mới.
            $reason === 'shift_never_online'   => 'Không online suốt cả ca',
            $reason === 'shift_online_high'    => 'Online ≥ 90% thời lượng ca',
            $reason === 'shift_online_neutral' => 'Online 70–89% thời lượng ca',
            $reason === 'offer_unviewed_x3'  => 'Bỏ lỡ 3 đơn không xem',
            $reason === 'streak_bonus'     => 'Thưởng chuỗi đơn liên tiếp',
            $reason === 'inactive_1_day' || $reason === 'inactivity_1d'
                => 'Không giao đơn 1 ngày',
            $reason === 'inactive_2_day' || $reason === 'inactivity_2d'
                => 'Không giao đơn 2+ ngày',
            $reason === 'inactivity_14d'
                => 'Không giao đơn 14+ ngày',
            $reason === 'online_time_low' || $reason === 'online_below_8h'
                => 'Online dưới 8 giờ',
            str_starts_with($reason, 'complete+streak_')
                => 'Hoàn thành đơn — đạt mốc ' . str_replace('complete+streak_', '', $reason) . ' đơn liên tiếp',
            str_starts_with($reason, 'streak_')
                => 'Streak ' . str_replace('streak_', '', $reason) . ' đơn liên tiếp',
            str_starts_with($reason, 'rated_') && str_ends_with($reason, '_stars')
                => 'Khách đánh giá ' . str_replace(['rated_', '_stars'], '', $reason) . ' sao',
            str_starts_with($reason, 'cap_blocked:')
                => 'Đã đạt giới hạn +10đ/ngày (' . self::reasonLabel(str_replace('cap_blocked:', '', $reason)) . ')',
            $reason === 'refund_wrong_penalty' => 'Hoàn lại điểm bị trừ nhầm',
            str_starts_with($reason, 'refund_') => 'Hoàn lại điểm',
            default => $reason,
        };
    }
}
