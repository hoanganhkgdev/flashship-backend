<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Shift;
use Modules\Driver\Models\DriverScoreLog;
use Modules\Driver\Models\DriverShiftSession;
use Modules\Driver\Services\DriverScoreService;

class ScoreShiftSessionsCommand extends Command
{
    protected $signature   = 'drivers:score-shift-sessions';
    protected $description = 'Chấm điểm % thời gian online + % offer bỏ lỡ cuối mỗi ca vừa kết thúc';

    private const SCORE_REASONS = [
        'shift_online_high', 'shift_online_neutral', 'shift_online_mid', 'shift_online_low', 'shift_violation',
        'missed_offer_neutral', 'missed_offer_low', 'missed_offer_mid', 'missed_offer_high',
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

                // TẠM DỪNG (2026-08-03): đợt backfill an toàn đăng ký MỌI tài xế
                // vào TẤT CẢ ca của khu vực (kể cả ca họ không thật sự làm) để
                // tránh chặn phát đơn ngay lúc deploy — hệ quả phụ là chấm %
                // online theo ca sai be bét cho các ca "ảo" đó. Tắt riêng phần
                // "Có mặt", GIỮ NGUYÊN "Phản hồi" (% bỏ lỡ, không liên quan lỗi
                // này vì chỉ tính trên offer THẬT NHẬN được). Bật lại sau khi
                // app Flutter release và tài xế tự chỉnh đúng ca của mình.
                // $this->scoreDriverShift($driverId, $start, $end);
                $this->scoreDriverMissedOffers($driverId, $start, $end);
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

    /**
     * % offer KHÔNG mở xem / tổng offer nhận trong ca — thay luật "bỏ lỡ 2
     * lần liên tiếp → tự tắt + phạt" cũ. Không có offer nào trong ca (mẫu
     * số = 0) thì không đủ căn cứ để chấm, bỏ qua hoàn toàn.
     */
    private function scoreDriverMissedOffers(int $driverId, Carbon $start, Carbon $end): void
    {
        $offers = DB::table('order_dispatch_logs')
            ->where('driver_id', $driverId)
            ->where('offered_at', '>=', $start)
            ->where('offered_at', '<', $end)
            ->get(['viewed_at']);

        if ($offers->isEmpty()) {
            return;
        }

        $missedPercent = $offers->whereNull('viewed_at')->count() / $offers->count();

        if ($missedPercent > 0 && $this->isCityRaining($driverId)) {
            // Lỡ đơn lúc mưa to nhiều khả năng chính đáng (đường ngập, nguy
            // hiểm), không phải né đơn — miễn chấm hoàn toàn, giống cơ chế cũ.
            return;
        }

        DriverScoreService::onMissedOfferRate($driverId, $missedPercent);
    }

    private function isCityRaining(int $driverId): bool
    {
        $cityId = DB::table('users')->where('id', $driverId)->value('city_id');
        return (bool) DB::table('cities')->where('id', $cityId)->value('is_rain_mode');
    }
}
