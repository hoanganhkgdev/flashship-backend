<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Shift;
use Modules\Driver\Models\DriverLeaveRequest;
use Modules\Driver\Models\DriverScoreLog;
use Modules\Driver\Models\DriverShiftSession;
use Modules\Driver\Services\DriverScoreService;

class ScoreShiftSessionsCommand extends Command
{
    protected $signature   = 'drivers:score-shift-sessions';
    protected $description = 'Chấm điểm % thời gian online cuối mỗi ca vừa kết thúc';

    private const SCORE_REASONS = [
        'shift_online_high', 'shift_online_neutral', 'shift_online_mid', 'shift_online_low', 'shift_never_online',
    ];

    public function handle(): void
    {
        $now = Carbon::now();
        $scored = 0;

        foreach (Shift::active()->get() as $shift) {
            $window = $this->recentlyEndedWindow($shift, $now);
            if (!$window) {
                continue;
            }
            [$start, $end] = $window;

            $driverIds = $shift->users()->pluck('users.id');
            foreach ($driverIds as $driverId) {
                if ($this->alreadyScored($driverId, $start, $end)) {
                    continue;
                }

                // BẬT LẠI (2026-08-06): chấp nhận rủi ro dữ liệu ca "ảo" còn sót
                // (một số tài xế chưa tự sửa lại đúng ca thật sau đợt backfill
                // an toàn 2026-08-03) — quyết định chủ động, không chờ 100% tài
                // xế tự sửa nữa.
                $this->scoreDriverShift($driverId, $start, $end);
                $scored++;
            }
        }

        $this->info("[ScoreShiftSessions] Đã chấm {$scored} lượt tài xế/ca.");
        Log::info("[ScoreShiftSessions] Đã chấm {$scored} lượt tài xế/ca.");
    }

    /**
     * Thử 2 mốc neo — "ca bắt đầu hôm nay" và "ca bắt đầu hôm qua" (cho ca vắt
     * qua nửa đêm vừa kết thúc sáng sớm hôm nay) — trả về cửa sổ [start, end)
     * nào có mốc kết thúc rơi đúng vào 5 phút gần đây, nếu không cái nào khớp
     * thì ca này chưa vừa kết thúc, bỏ qua.
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function recentlyEndedWindow(Shift $shift, Carbon $now): ?array
    {
        foreach ([0, -1] as $dayOffset) {
            $start = Carbon::today()->addDays($dayOffset)->setTimeFromTimeString($shift->start_time);
            $end   = Carbon::today()->addDays($dayOffset)->setTimeFromTimeString($shift->end_time);
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            if ($end->lessThanOrEqualTo($now) && $end->greaterThan($now->copy()->subMinutes(5))) {
                return [$start, $end];
            }
        }

        return null;
    }

    private function alreadyScored(int $driverId, Carbon $start, Carbon $end): bool
    {
        return DriverScoreLog::where('driver_id', $driverId)
            ->whereIn('reason', self::SCORE_REASONS)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end->copy()->addMinutes(10))
            ->exists();
    }

    private function scoreDriverShift(int $driverId, Carbon $start, Carbon $end): void
    {
        // Đã xin nghỉ phép trước (admin ghi nhận qua DriverLeaveRequestResource)
        // → miễn chấm hoàn toàn cho ca rơi vào ngày nghỉ đó, không bị tính
        // -15 vì không online.
        if (DriverLeaveRequest::where('driver_id', $driverId)
            ->whereDate('leave_date', $start->toDateString())
            ->exists()) {
            return;
        }

        $sessions = DriverShiftSession::where('driver_id', $driverId)
            ->where('started_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))
            ->get();

        $onlineSeconds = 0;
        foreach ($sessions as $session) {
            $sessionStart = $session->started_at->greaterThan($start) ? $session->started_at : $start;
            $sessionEnd   = $session->ended_at && $session->ended_at->lessThan($end) ? $session->ended_at : $end;
            $onlineSeconds += max(0, $sessionStart->diffInSeconds($sessionEnd, false));
        }

        $shiftDuration = max(1, $start->diffInSeconds($end));
        $percent       = min(1.0, $onlineSeconds / $shiftDuration);
        DriverScoreService::onShiftOnlineRate($driverId, $percent);
    }

}
