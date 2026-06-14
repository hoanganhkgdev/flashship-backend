<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Driver\Services\DriverScoreService;

class WeeklyScoreCommand extends Command
{
    protected $signature   = 'drivers:weekly-score';
    protected $description = 'Chốt điểm cuối tuần (thưởng/phạt 50k) rồi reset về 100 cho tuần mới';

    public function handle(): void
    {
        $weekStart = Carbon::now()->startOfWeek()->toDateString();  // Thứ Hai
        $weekEnd   = Carbon::now()->endOfWeek()->toDateString();    // Chủ Nhật

        // Guard: chỉ chạy 1 lần/tuần
        $ran = DB::table('driver_score_settlements')
            ->where('week_start', $weekStart)
            ->exists();

        if ($ran) {
            $this->info("[WeeklyScore] Đã chạy cho tuần {$weekStart}, bỏ qua.");
            return;
        }

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->select('id', 'driver_score')
            ->get();

        $bonusCount   = 0;
        $penaltyCount = 0;
        $now          = now()->toDateTimeString();

        $settlements = [];

        foreach ($drivers as $driver) {
            $score = $driver->driver_score ?? DriverScoreService::DEFAULT_SCORE;

            if ($score >= DriverScoreService::WEEKLY_BONUS_SCORE) {
                $settlements[] = [
                    'driver_id'          => $driver->id,
                    'type'               => 'bonus',
                    'amount'             => DriverScoreService::WEEKLY_BONUS_AMOUNT,
                    'score_at_settlement'=> $score,
                    'week_start'         => $weekStart,
                    'week_end'           => $weekEnd,
                    'status'             => 'pending',
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
                $bonusCount++;
            } elseif ($score <= DriverScoreService::WEEKLY_PENALTY_SCORE) {
                $settlements[] = [
                    'driver_id'          => $driver->id,
                    'type'               => 'penalty',
                    'amount'             => DriverScoreService::WEEKLY_PENALTY_AMOUNT,
                    'score_at_settlement'=> $score,
                    'week_start'         => $weekStart,
                    'week_end'           => $weekEnd,
                    'status'             => 'pending',
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
                $penaltyCount++;
            }
        }

        if (!empty($settlements)) {
            DB::table('driver_score_settlements')->insert($settlements);
        }

        // Reset toàn bộ điểm về 100 cho tuần mới
        DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->update([
                'driver_score'          => DriverScoreService::DEFAULT_SCORE,
                'consecutive_completed' => 0,
                'daily_bonus_points'    => 0,
                'daily_bonus_date'      => null,
                'score_suspended_until' => null,
            ]);

        $total = $drivers->count();
        $this->info("[WeeklyScore] Tuần {$weekStart}→{$weekEnd}: {$bonusCount} thưởng, {$penaltyCount} phạt / {$total} tài xế → reset về 100.");
        Log::info("[WeeklyScore] bonus={$bonusCount} penalty={$penaltyCount} total={$total} week={$weekStart}");
    }
}
