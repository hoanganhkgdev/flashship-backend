<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Shift;
use Modules\Driver\Models\DriverLeaveRequest;
use Modules\Driver\Models\DriverGpsEligibleSession;
use Modules\Driver\Services\DriverScoreService;

class ScoreShiftSessionsCommand extends Command
{
    protected $signature   = 'drivers:score-shift-sessions';
    protected $description = 'Chấm điểm % thời gian online cuối mỗi ca vừa kết thúc';

    public function handle(): void
    {
        $now = Carbon::now();
        $scored = 0;

        foreach (Shift::active()->get() as $shift) {
            foreach ($this->recentlyEndedWindows($shift, $now) as [$start, $end]) {
                $driverIds = $shift->users()->pluck('users.id');
                foreach ($driverIds as $driverId) {
                    // Claim nằm cùng transaction với chấm điểm. Unique key
                    // chặn hai scheduler chấm cùng ca; nếu chấm lỗi thì cả
                    // claim rollback để cron sau có thể thử lại.
                    $claimed = DB::transaction(function () use ($shift, $driverId, $start, $end) {
                        $inserted = DB::table('driver_shift_score_runs')->insertOrIgnore([
                            'driver_id' => $driverId,
                            'shift_id' => $shift->id,
                            'shift_started_at' => $start,
                            'shift_ended_at' => $end,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        if (!$inserted) return false;

                        $this->scoreDriverShift($driverId, $start, $end);
                        return true;
                    });
                    if ($claimed) $scored++;
                }
            }
        }

        $this->info("[ScoreShiftSessions] Đã chấm {$scored} lượt tài xế/ca.");
        Log::info("[ScoreShiftSessions] Đã chấm {$scored} lượt tài xế/ca.");
    }

    /**
     * Thử 2 mốc neo — "ca bắt đầu hôm nay" và "ca bắt đầu hôm qua" (cho ca vắt
     * qua nửa đêm vừa kết thúc sáng sớm hôm nay) — trả về cửa sổ [start, end)
     * nào có mốc kết thúc rơi đúng vào 5 phút gần đây, nếu không cái nào khớp
     * thì ca này chưa kết thúc trong cửa sổ bù 24 giờ, bỏ qua.
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function recentlyEndedWindows(Shift $shift, Carbon $now): array
    {
        $windows = [];
        foreach ([0, -1, -2] as $dayOffset) {
            $start = Carbon::today()->addDays($dayOffset)->setTimeFromTimeString($shift->start_time);
            $end   = Carbon::today()->addDays($dayOffset)->setTimeFromTimeString($shift->end_time);
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            if ($end->lessThanOrEqualTo($now) && $end->greaterThan($now->copy()->subDay())) {
                $windows[] = [$start, $end];
            }
        }

        return $windows;
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

        // Chỉ cộng các khoảng Online có GPS tươi, không dùng trực
        // tiếp "ý định Online". Vì vậy tắt GPS để né đơn không
        // được tính giờ; mất GPS rồi có lại chỉ loại đúng khoảng mất.
        $sessions = DriverGpsEligibleSession::where('driver_id', $driverId)
            ->where('started_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))
            ->orderBy('started_at')
            ->get();

        // Gộp các khoảng [start, end] chồng lấp trước khi cộng dồn — nếu 2
        // phiên online chồng nhau (race condition ở toggleOnline() khi 2
        // request bật online gần như đồng thời trước đây tạo phiên trùng)
        // thì cộng riêng từng phiên sẽ đếm trùng thời gian, có thể vượt cả
        // thời lượng ca và bị min(1.0, ...) kẹp thành 100% online — che mất
        // khoảng thời gian tài xế thực sự offline trong ca.
        $intervals = [];
        foreach ($sessions as $session) {
            $sessionStart = $session->started_at->greaterThan($start) ? $session->started_at : $start;
            $sessionEnd   = $session->ended_at && $session->ended_at->lessThan($end) ? $session->ended_at : $end;
            if ($sessionEnd->lessThanOrEqualTo($sessionStart)) {
                continue;
            }
            $intervals[] = [$sessionStart->timestamp, $sessionEnd->timestamp];
        }
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);

        $onlineSeconds = 0;
        $mergedEnd     = null;
        foreach ($intervals as [$intervalStart, $intervalEnd]) {
            if ($mergedEnd === null || $intervalStart > $mergedEnd) {
                $onlineSeconds += $intervalEnd - $intervalStart;
                $mergedEnd = $intervalEnd;
            } elseif ($intervalEnd > $mergedEnd) {
                $onlineSeconds += $intervalEnd - $mergedEnd;
                $mergedEnd = $intervalEnd;
            }
        }

        $shiftDuration = max(1, $start->diffInSeconds($end));
        $percent       = min(1.0, $onlineSeconds / $shiftDuration);
        DriverScoreService::onShiftOnlineRate($driverId, $percent);
    }

}
