<?php

namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\DriverGeoService;
use Modules\Core\Services\RTDBService;

class GhostDriverCleanupCommand extends Command
{
    protected $signature   = 'driver:ghost-cleanup';
    protected $description = 'Set offline tài xế không gửi vị trí > 2 phút (app crash / mất mạng)';

    public function handle(): int
    {
        $now    = now();
        $cutoff = $now->copy()->subSeconds(120);

        $ghosts = DB::table('users')
            ->where('user_type', 'driver')
            ->where('is_online', true)
            ->whereNotNull('city_id')
            ->where(function ($q) use ($cutoff) {
                $q->where('last_location_at', '<', $cutoff)
                  ->orWhere(function ($q2) {
                      // Driver đi online trước khi có cột last_location_at — grace 5 phút
                      $q2->whereNull('last_location_at')
                         ->where('online_since', '<', now()->subMinutes(5));
                  });
            })
            ->get(['id', 'city_id', 'online_since', 'daily_online_seconds', 'daily_online_date']);

        if ($ghosts->isEmpty()) {
            return self::SUCCESS;
        }

        $today = $now->toDateString();
        $count = 0;

        foreach ($ghosts as $ghost) {
            $accumulated = ($ghost->daily_online_date === $today)
                ? (int) ($ghost->daily_online_seconds ?? 0)
                : 0;

            $sessionSecs = 0;
            if ($ghost->online_since) {
                $onlineSince = Carbon::parse($ghost->online_since);
                $start       = ($onlineSince->toDateString() === $today)
                    ? $onlineSince
                    : $now->copy()->startOfDay();
                $sessionSecs = (int) abs($start->diffInSeconds($now));
            }

            DB::table('users')->where('id', $ghost->id)->update([
                'is_online'            => false,
                'online_since'         => null,
                'daily_online_seconds' => $accumulated + $sessionSecs,
                'daily_online_date'    => $today,
                'updated_at'           => $now,
            ]);

            DriverGeoService::remove($ghost->id, $ghost->city_id);
            RTDBService::setDriverOnlineStatus($ghost->id, false);

            $count++;
        }

        if ($count > 0) {
            Log::info("[GhostCleanup] Set offline {$count} ghost driver(s)");
        }

        return self::SUCCESS;
    }
}
