<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Shift;
use Modules\Driver\Models\DriverScoreLog;
use Modules\Driver\Models\DriverShiftSession;
use Modules\Driver\Services\DriverScoreService;

class ScoreShiftSessionsCommand extends Command
{
    protected $signature   = 'drivers:score-shift-sessions';
    protected $description = 'Chấm điểm % thời gian online cuối mỗi ca vừa kết thúc (thay luật 8h/ngày cũ)';

    private const SCORE_REASONS = ['shift_online_high', 'shift_online_neutral', 'shift_online_mid', 'shift_online_low', 'shift_violation'];

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
        $sessions = DriverShiftSession::where('driver_id', $driverId)
            ->where('started_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))
            ->get();

        $onlineSeconds = 0;
        $presentAtEnd  = false;

        foreach ($sessions as $session) {
            $sessionStart = $session->started_at->greaterThan($start) ? $session->started_at : $start;
            $sessionEnd   = $session->ended_at && $session->ended_at->lessThan($end) ? $session->ended_at : $end;
            $onlineSeconds += max(0, $sessionStart->diffInSeconds($sessionEnd, false));

            if (!$session->ended_at || $session->ended_at->greaterThanOrEqualTo($end)) {
                $presentAtEnd = true;
            }
        }

        if ($onlineSeconds <= 0) {
            // Không online chút nào trong ca — không phải "tắt hẳn giữa ca" (vì
            // chưa từng bật), rơi vào tier <50% bình thường (0%).
            DriverScoreService::onShiftOnlineRate($driverId, 0.0);
            return;
        }

        if (!$presentAtEnd) {
            // Có online 1 đoạn rồi tắt hẳn, không bật lại tới hết ca.
            DriverScoreService::onShiftViolation($driverId);
            return;
        }

        $shiftDuration = max(1, $start->diffInSeconds($end));
        $percent       = min(1.0, $onlineSeconds / $shiftDuration);
        DriverScoreService::onShiftOnlineRate($driverId, $percent);
    }
}
