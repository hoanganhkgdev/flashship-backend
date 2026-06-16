<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Driver\Services\DriverScoreService;
use Modules\Driver\Services\DriverWalletService;

class WeeklyScoreCommand extends Command
{
    protected $signature   = 'drivers:weekly-score';
    protected $description = 'Chốt điểm cuối tuần (thưởng/phạt 50k vào ví) rồi reset về 100 cho tuần mới';

    public function handle(): void
    {
        $weekStart = Carbon::now()->startOfWeek()->toDateString();
        $weekEnd   = Carbon::now()->endOfWeek()->toDateString();

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

        foreach ($drivers as $driver) {
            $score = (int) ($driver->driver_score ?? DriverScoreService::DEFAULT_SCORE);

            if ($score >= DriverScoreService::WEEKLY_BONUS_SCORE) {
                $ref = "score_bonus_{$driver->id}_{$weekStart}";

                try {
                    DriverWalletService::adjust(
                        driverId:      $driver->id,
                        amount:        DriverScoreService::WEEKLY_BONUS_AMOUNT,
                        type:          'credit',
                        desc:          "Thưởng điểm tuần {$weekStart} — {$weekEnd} (điểm: {$score})",
                        ref:           $ref,
                    );
                    $status = 'processed';
                } catch (\Throwable $e) {
                    Log::warning("[WeeklyScore] Lỗi thưởng driver #{$driver->id}: " . $e->getMessage());
                    $status = 'pending';
                }

                DB::table('driver_score_settlements')->insert([
                    'driver_id'           => $driver->id,
                    'type'                => 'bonus',
                    'amount'              => DriverScoreService::WEEKLY_BONUS_AMOUNT,
                    'score_at_settlement' => $score,
                    'week_start'          => $weekStart,
                    'week_end'            => $weekEnd,
                    'status'              => $status,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
                $bonusCount++;

            } elseif ($score <= DriverScoreService::WEEKLY_PENALTY_SCORE) {
                $ref = "score_penalty_{$driver->id}_{$weekStart}";

                try {
                    DriverWalletService::adjust(
                        driverId:      $driver->id,
                        amount:        DriverScoreService::WEEKLY_PENALTY_AMOUNT,
                        type:          'debit',
                        desc:          "Phạt điểm tuần {$weekStart} — {$weekEnd} (điểm: {$score})",
                        ref:           $ref,
                        allowNegative: true,
                    );
                    $status = 'processed';
                } catch (\Throwable $e) {
                    Log::warning("[WeeklyScore] Lỗi phạt driver #{$driver->id}: " . $e->getMessage());
                    $status = 'pending';
                }

                DB::table('driver_score_settlements')->insert([
                    'driver_id'           => $driver->id,
                    'type'                => 'penalty',
                    'amount'              => DriverScoreService::WEEKLY_PENALTY_AMOUNT,
                    'score_at_settlement' => $score,
                    'week_start'          => $weekStart,
                    'week_end'            => $weekEnd,
                    'status'              => $status,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
                $penaltyCount++;
            }
        }

        // Reset toàn bộ điểm và streak về mặc định cho tuần mới
        DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->update([
                'driver_score'          => DriverScoreService::DEFAULT_SCORE,
                'consecutive_completed' => 0,
            ]);

        $total = $drivers->count();
        $this->info("[WeeklyScore] Tuần {$weekStart}→{$weekEnd}: {$bonusCount} thưởng, {$penaltyCount} phạt / {$total} tài xế → reset về 100.");
        Log::info("[WeeklyScore] bonus={$bonusCount} penalty={$penaltyCount} total={$total} week={$weekStart}");
    }
}
