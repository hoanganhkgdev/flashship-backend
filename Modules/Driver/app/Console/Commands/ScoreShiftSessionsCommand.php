<?php

namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Shift;
use Modules\Driver\Models\DriverLeaveRequest;
use Modules\Driver\Models\DriverShiftSession;
use Modules\Driver\Services\DriverScoreService;
use Modules\Driver\Services\ShiftOnlineTimeCalculator;

class ScoreShiftSessionsCommand extends Command
{
    protected $signature = 'drivers:score-shift-sessions';

    protected $description = 'Chấm điểm % thời gian online cuối mỗi ca vừa kết thúc';

    public function handle(): void
    {
        $now = Carbon::now();
        $scored = 0;

        foreach (Shift::active()->get() as $shift) {
            foreach ($this->recentlyEndedWindows($shift, $now) as [$start, $end]) {
                $drivers = $shift->users()->get(['users.id']);
                foreach ($drivers as $driver) {
                    $driverId = $driver->id;

                    // Ca mới chỉ có hiệu lực từ lúc được gán. Nếu admin duyệt
                    // đổi ca sau khi một cửa sổ ca đã bắt đầu/kết thúc, không
                    // được dùng quan hệ hiện tại để chấm ngược cửa sổ cũ.
                    // created_at=null là dữ liệu legacy có trước khi hệ thống
                    // bắt đầu lưu thời điểm gán, nên vẫn được chấm như cũ.
                    $assignedAt = $driver->pivot?->created_at;
                    if (! $this->assignmentWasEffective($assignedAt, $start)) {
                        Log::info("[ScoreShiftSessions] Bỏ qua driver #{$driverId}: ca #{$shift->id} được gán lúc {$assignedAt}, sau khi ca bắt đầu {$start}.");

                        continue;
                    }

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
                        if (! $inserted) {
                            return false;
                        }

                        $this->scoreDriverShift($driverId, $start, $end);

                        return true;
                    });
                    if ($claimed) {
                        $scored++;
                    }
                }
            }
        }

        $this->info("[ScoreShiftSessions] Đã chấm {$scored} lượt tài xế/ca.");
        Log::info("[ScoreShiftSessions] Đã chấm {$scored} lượt tài xế/ca.");
    }

    protected function assignmentWasEffective(mixed $assignedAt, Carbon $shiftStart): bool
    {
        return ! $assignedAt || Carbon::parse($assignedAt)->lessThanOrEqualTo($shiftStart);
    }

    /**
     * Thử 2 mốc neo — "ca bắt đầu hôm nay" và "ca bắt đầu hôm qua" (cho ca vắt
     * qua nửa đêm vừa kết thúc sáng sớm hôm nay) — trả về cửa sổ [start, end)
     * nào có mốc kết thúc rơi đúng vào 5 phút gần đây, nếu không cái nào khớp
     * thì ca này chưa kết thúc trong cửa sổ bù 24 giờ, bỏ qua.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function recentlyEndedWindows(Shift $shift, Carbon $now): array
    {
        $windows = [];
        foreach ([0, -1, -2] as $dayOffset) {
            $start = Carbon::today()->addDays($dayOffset)->setTimeFromTimeString($shift->start_time);
            $end = Carbon::today()->addDays($dayOffset)->setTimeFromTimeString($shift->end_time);
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

        // Giờ ca lấy từ nút Online/Offline do backend ghi nhận. GPS chỉ phục
        // vụ phát đơn; GPS chập chờn hoặc Firebase lỗi không được làm mất giờ.
        $sessions = DriverShiftSession::where('driver_id', $driverId)
            ->where('started_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))
            ->orderBy('started_at')
            ->get();

        $onlineSeconds = app(ShiftOnlineTimeCalculator::class)->seconds($sessions, $start, $end);

        $shiftDuration = max(1, $start->diffInSeconds($end));
        $percent = min(1.0, $onlineSeconds / $shiftDuration);
        DriverScoreService::onShiftOnlineRate($driverId, $percent);
    }
}
