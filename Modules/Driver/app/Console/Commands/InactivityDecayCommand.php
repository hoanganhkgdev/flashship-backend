<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Driver\Services\DriverScoreService;

class InactivityDecayCommand extends Command
{
    protected $signature   = 'drivers:daily-decay';
    protected $description = 'Cuối ngày: trừ điểm tài xế không hoạt động và online dưới 8 giờ';

    public function handle(): void
    {
        $today     = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->whereDate('created_at', '<', $today) // bỏ qua tài xế mới tạo hôm nay
            ->select('id', 'driver_last_active_date', 'is_online', 'online_since',
                     'daily_online_seconds', 'daily_online_date')
            ->get();

        $inactivity1 = 0;
        $inactivity2 = 0;
        $lowOnline   = 0;

        foreach ($drivers as $driver) {

            // ── 1. Kiểm tra online time ────────────────────────────────────────
            // Chỉ phạt nếu có dữ liệu tracking hôm nay (daily_online_date = today)
            // hoặc đang online. Nếu không có dữ liệu gì → bỏ qua (tài xế chưa dùng
            // tính năng tracking mới, không đủ cơ sở để phạt).
            $hasOnlineData = ($driver->daily_online_date === $today) || $driver->is_online;

            if ($hasOnlineData) {
                $onlineSeconds = ($driver->daily_online_date === $today)
                    ? (int) ($driver->daily_online_seconds ?? 0)
                    : 0;

                if ($driver->is_online && $driver->online_since) {
                    $onlineSince     = Carbon::parse($driver->online_since);
                    $onlineSinceDate = $onlineSince->toDateString();
                    $sessionStart    = ($onlineSinceDate === $today)
                        ? $onlineSince
                        : Carbon::today();
                    $onlineSeconds  += max(0, (int) $sessionStart->diffInSeconds(now()));
                }

                if ($onlineSeconds < DriverScoreService::MIN_ONLINE_SECONDS) {
                    DriverScoreService::onLowOnlineTime($driver->id);
                    $lowOnline++;
                }
            }

            // Reset counter hàng ngày dù có hay không có data
            DB::table('users')->where('id', $driver->id)->update([
                'daily_online_seconds' => 0,
                'daily_online_date'    => null,
            ]);

            // ── 2. Kiểm tra hoạt động giao đơn ───────────────────────────────
            // Nếu driver_last_active_date = null → chưa có dữ liệu (cột mới hoặc
            // chưa từng hoàn thành đơn trong hệ thống mới) → bỏ qua, không phạt.
            $lastActive = $driver->driver_last_active_date;

            if ($lastActive === null) continue; // Chưa có dữ liệu → không phạt
            if ($lastActive === $today) continue; // Đã hoạt động hôm nay → không phạt

            if ($lastActive === $yesterday) {
                DriverScoreService::onInactivity($driver->id, 1);
                $inactivity1++;
            } else {
                DriverScoreService::onInactivity($driver->id, 2);
                $inactivity2++;
            }
        }

        $total = $drivers->count();
        $this->info("[DailyDecay] {$total} tài xế | online<8h: {$lowOnline} | inactive1: {$inactivity1} | inactive2+: {$inactivity2}");
        Log::info("[DailyDecay] lowOnline={$lowOnline} inactive1={$inactivity1} inactive2={$inactivity2} total={$total} date={$today}");
    }
}
