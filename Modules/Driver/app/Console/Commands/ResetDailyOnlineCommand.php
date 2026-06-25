<?php

namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResetDailyOnlineCommand extends Command
{
    protected $signature   = 'driver:reset-daily-online';
    protected $description = 'Reset thời gian online hàng ngày lúc 00:00, tích luỹ session qua đêm cho ngày cũ';

    public function handle(): int
    {
        $now       = Carbon::now();
        $today     = $now->toDateString();
        $midnight  = $now->copy()->startOfDay();

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->whereNotNull('daily_online_date')
            ->where('daily_online_date', '<', $today)
            ->get(['id', 'is_online', 'online_since', 'daily_online_seconds', 'daily_online_date']);

        $reset   = 0;
        $carried = 0;

        foreach ($drivers as $d) {
            $update = [
                'daily_online_seconds' => 0,
                'daily_online_date'    => $today,
            ];

            if ($d->is_online && $d->online_since) {
                $onlineSince = Carbon::parse($d->online_since);

                // Tích luỹ phần trước 00:00 cho ngày cũ
                if ($onlineSince->lt($midnight)) {
                    $yesterdaySeconds = (int) abs($onlineSince->diffInSeconds($midnight));
                    $oldTotal = (int) ($d->daily_online_seconds ?? 0) + $yesterdaySeconds;

                    Log::info("[DailyReset] Driver #{$d->id}: tích luỹ {$yesterdaySeconds}s cho {$d->daily_online_date} (tổng: {$oldTotal}s)");
                }

                // Bắt đầu session mới từ lúc reset
                $update['online_since'] = $now;
                $carried++;
            }

            DB::table('users')->where('id', $d->id)->update($update);
            $reset++;
        }

        $this->info("[DailyReset] Reset {$reset} tài xế, {$carried} đang online qua đêm.");
        Log::info("[DailyReset] Reset {$reset} tài xế, {$carried} online qua đêm.");

        return self::SUCCESS;
    }
}
