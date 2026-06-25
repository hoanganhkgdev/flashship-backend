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
    protected $description = 'Cuối ngày (00:00): xét online 6:30-00:00 có đủ 8h không, trừ điểm, reset time';

    public function handle(): void
    {
        $today     = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();
        $dayStart  = Carbon::today()->setTime(6, 30, 0);

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->whereDate('created_at', '<', $today)
            ->select('id', 'driver_last_active_date', 'is_online', 'online_since',
                     'daily_online_seconds', 'daily_online_date')
            ->get();

        $lowOnline   = 0;
        $inactivity1 = 0;
        $inactivity2 = 0;

        foreach ($drivers as $driver) {

            // ── 1. Tính online time từ 6:30 đến 00:00 ────────────────────────
            $onlineSeconds = ($driver->daily_online_date === $today)
                ? (int) ($driver->daily_online_seconds ?? 0)
                : 0;

            // Nếu đang online, cộng thêm session hiện tại (chỉ phần từ 6:30)
            if ($driver->is_online && $driver->online_since) {
                $onlineSince  = Carbon::parse($driver->online_since);
                $sessionStart = $onlineSince->lt($dayStart) ? $dayStart : $onlineSince;
                $onlineSeconds += (int) abs($sessionStart->diffInSeconds(now()));
            }

            // Chỉ phạt nếu có dữ liệu tracking
            $hasData = ($driver->daily_online_date === $today) || $driver->is_online;
            if ($hasData && $onlineSeconds < DriverScoreService::MIN_ONLINE_SECONDS) {
                DriverScoreService::onLowOnlineTime($driver->id);
                $lowOnline++;
            }

            // Reset time online — ca đêm 00:01-06:30 sẽ đếm riêng
            DB::table('users')->where('id', $driver->id)->update([
                'daily_online_seconds' => 0,
                'daily_online_date'    => null,
            ]);

            // Tài xế đang online → set online_since = now (bắt đầu đếm ca đêm)
            if ($driver->is_online) {
                DB::table('users')->where('id', $driver->id)->update([
                    'online_since' => now(),
                ]);
            }

            // ── 2. Kiểm tra hoạt động giao đơn ──────────────────────────────
            $lastActive = $driver->driver_last_active_date;
            if ($lastActive === null) continue;
            if ($lastActive === $today) continue;

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
        Log::info("[DailyDecay] lowOnline={$lowOnline} inactive1={$inactivity1} inactive2={$inactivity2} total={$total}");
    }
}
