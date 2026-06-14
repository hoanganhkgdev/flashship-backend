<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Services\DriverScoreService;

class DecayDriverScoresCommand extends Command
{
    protected $signature   = 'drivers:decay-scores';
    protected $description = 'Kiểm tra hoạt động hàng ngày: trừ điểm không hoạt động & thiếu giờ online';

    // Không hoàn thành đơn nào trong N ngày
    const DAYS_MILD   = 1;
    const DECAY_MILD  = -5;

    const DAYS_SEVERE  = 2;
    const DECAY_SEVERE = -10;

    // Online tối thiểu/ngày
    const MIN_ONLINE_SECONDS = DriverScoreService::MIN_ONLINE_SECONDS;
    const ONLINE_PENALTY     = -5;

    public function handle(): void
    {
        $yesterday = now()->subDay()->toDateString();

        // Guard: chỉ chạy 1 lần/ngày
        $ranToday = DB::table('driver_score_logs')
            ->whereIn('reason', ['inactivity_1d', 'inactivity_2d', 'online_below_8h'])
            ->whereDate('created_at', now()->toDateString())
            ->exists();

        if ($ranToday) {
            $this->info('[DailyDecay] Đã chạy hôm nay, bỏ qua.');
            return;
        }

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->select('id', 'is_online', 'online_since', 'daily_online_seconds', 'daily_online_date')
            ->get();

        // Lấy ngày hoàn thành đơn gần nhất của từng tài xế
        $lastCompleted = DB::table('orders')
            ->where('status', 'completed')
            ->whereNotNull('delivery_man_id')
            ->select('delivery_man_id', DB::raw('MAX(completed_at) as last_at'))
            ->groupBy('delivery_man_id')
            ->pluck('last_at', 'delivery_man_id');

        $inactivity1 = $inactivity2 = $onlineLow = 0;

        foreach ($drivers as $driver) {

            // ── 1. Kiểm tra không hoạt động ──────────────────────────────
            $lastAt    = $lastCompleted[$driver->id] ?? null;
            $daysSince = $lastAt
                ? now()->diffInDays(Carbon::parse($lastAt))
                : PHP_INT_MAX;

            if ($daysSince >= self::DAYS_SEVERE) {
                DriverScoreService::onDecay($driver->id, self::DECAY_SEVERE, 'inactivity_2d');
                $inactivity2++;
            } elseif ($daysSince >= self::DAYS_MILD) {
                DriverScoreService::onDecay($driver->id, self::DECAY_MILD, 'inactivity_1d');
                $inactivity1++;
            }

            // ── 2. Kiểm tra thời gian online hôm qua ─────────────────────
            $onlineSecs = 0;

            // Thời gian đã tích lũy trong ngày hôm qua
            if ($driver->daily_online_date === $yesterday) {
                $onlineSecs += (int) $driver->daily_online_seconds;
            }

            // Nếu đang online từ hôm qua, cộng thêm phần trước nửa đêm
            if ($driver->is_online && $driver->online_since) {
                $since = Carbon::parse($driver->online_since);
                if ($since->toDateString() === $yesterday) {
                    $midnight = Carbon::parse($yesterday)->endOfDay();
                    $onlineSecs += (int) $since->diffInSeconds($midnight);
                }
            }

            if ($onlineSecs < self::MIN_ONLINE_SECONDS) {
                DriverScoreService::onDecay($driver->id, self::ONLINE_PENALTY, 'online_below_8h');
                $onlineLow++;
            }
        }

        $this->info(sprintf(
            '[DailyDecay] Hoàn tất: %d tài xế -5đ (1 ngày KHĐ), %d tài xế -10đ (2 ngày KHĐ), %d tài xế -5đ (online < 8h)',
            $inactivity1, $inactivity2, $onlineLow
        ));
    }
}
