<?php

namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResetDailyOnlineCommand extends Command
{
    protected $signature   = 'driver:reset-daily-online';
    protected $description = 'Reset thời gian online lúc 6:30, bắt đầu tính ngày mới';

    public function handle(): int
    {
        $now   = Carbon::now();
        $today = $now->toDateString();

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->where(function ($q) use ($today) {
                $q->where('daily_online_date', '<', $today)
                  ->orWhereNull('daily_online_date')
                  ->orWhere(function ($q2) {
                      $q2->where('is_online', true)
                         ->whereNotNull('online_since');
                  });
            })
            ->get(['id', 'is_online', 'online_since', 'daily_online_seconds', 'daily_online_date']);

        $reset = 0;

        foreach ($drivers as $d) {
            $update = [
                'daily_online_seconds' => 0,
                'daily_online_date'    => $today,
            ];

            if ($d->is_online) {
                $update['online_since'] = $now;
            }

            DB::table('users')->where('id', $d->id)->update($update);
            $reset++;
        }

        $this->info("[DailyReset] 06:30 reset {$reset} tài xế, bắt đầu ngày mới.");
        Log::info("[DailyReset] Reset {$reset} tài xế lúc 06:30.");

        return self::SUCCESS;
    }
}
