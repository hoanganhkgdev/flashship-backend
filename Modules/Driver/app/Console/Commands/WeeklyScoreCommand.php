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
    protected $description = 'Chốt thưởng/phạt điểm cuối tuần theo cấu hình vận hành rồi reset điểm cho tuần mới';

    public function handle(): void
    {
        $weekStart = Carbon::now()->subWeek()->startOfWeek()->toDateString();
        $weekEnd   = Carbon::now()->subWeek()->endOfWeek()->toDateString();
        // Chụp cấu hình một lần để toàn bộ tài xế trong cùng kỳ được áp dụng
        // đúng một chính sách, kể cả admin lưu thay đổi lúc command đang chạy.
        $bonusScore = DriverScoreService::weeklyBonusScore();
        $penaltyScore = DriverScoreService::weeklyPenaltyScore();
        $bonusAmount = DriverScoreService::weeklyBonusAmount();
        $penaltyAmount = DriverScoreService::weeklyPenaltyAmount();

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

            if ($score >= $bonusScore) {
                // Unique (driver_id, week_start, type) chặn xử lý trùng nếu lệnh
                // vô tình chạy 2 lần cho cùng tuần — bắt riêng từng tài xế để 1
                // lỗi trùng không làm crash cả vòng lặp (và mất luôn bước reset
                // điểm cuối hàm).
                try {
                    DB::transaction(function () use ($driver, $score, $weekStart, $weekEnd, $now, $bonusAmount) {
                        $ref = "score_bonus_{$driver->id}_{$weekStart}";

                        // Tạo settlement TRƯỚC — nếu trùng (đã chốt tuần này rồi),
                        // unique constraint chặn ngay, không kịp cộng ví.
                        DB::table('driver_score_settlements')->insert([
                            'driver_id'           => $driver->id,
                            'type'                => 'bonus',
                            'amount'              => $bonusAmount,
                            'score_at_settlement' => $score,
                            'week_start'          => $weekStart,
                            'week_end'            => $weekEnd,
                            'status'              => 'processed',
                            'created_at'          => $now,
                            'updated_at'          => $now,
                        ]);

                        DriverWalletService::adjust(
                            driverId: $driver->id,
                            amount:   $bonusAmount,
                            type:     'credit',
                            desc:     "Thưởng điểm tuần {$weekStart} — {$weekEnd} (điểm: {$score})",
                            ref:      $ref,
                        );
                    });
                    $bonusCount++;
                } catch (\Throwable $e) {
                    Log::warning("[WeeklyScore] Bỏ qua thưởng driver #{$driver->id} (có thể đã xử lý): " . $e->getMessage());
                }

            } elseif ($score <= $penaltyScore) {
                try {
                    DB::transaction(function () use ($driver, $score, $weekStart, $weekEnd, $now, $penaltyAmount) {
                        $ref = "score_penalty_{$driver->id}_{$weekStart}";

                        // Cũng tạo settlement TRƯỚC cùng lý do — nếu trùng thì
                        // rollback cả transaction, không để sót nợ mồ côi.
                        DB::table('driver_score_settlements')->insert([
                            'driver_id'           => $driver->id,
                            'type'                => 'penalty',
                            'amount'              => $penaltyAmount,
                            'score_at_settlement' => $score,
                            'week_start'          => $weekStart,
                            'week_end'            => $weekEnd,
                            'status'              => 'processed',
                            'created_at'          => $now,
                            'updated_at'          => $now,
                        ]);

                        DB::table('driver_debts')->insert([
                            'driver_id'  => $driver->id,
                            'status'     => 'pending',
                            'amount_due' => $penaltyAmount,
                            'amount_paid'=> 0,
                            'week_start' => $weekStart,
                            'week_end'   => $weekEnd,
                            'ref_id'     => $ref,
                            'note'       => "Phạt điểm tuần {$weekStart} — {$weekEnd} (điểm: {$score})",
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    });
                    $penaltyCount++;
                } catch (\Throwable $e) {
                    Log::warning("[WeeklyScore] Bỏ qua phạt driver #{$driver->id} (có thể đã xử lý): " . $e->getMessage());
                }
            }
        }

        // Reset toàn bộ điểm/streak về mặc định + ghi log lý do "weekly_reset"
        // (dùng chung DriverScoreService::weeklyReset() thay vì tự update thô
        // — trước đây update thẳng không ghi log, khiến lịch sử điểm tài xế
        // nhảy vọt về 100 mỗi sáng thứ Hai mà không có dòng log giải thích).
        DriverScoreService::weeklyReset();

        $total = $drivers->count();
        $this->info("[WeeklyScore] Tuần {$weekStart}→{$weekEnd}: {$bonusCount} thưởng, {$penaltyCount} phạt / {$total} tài xế → reset về 100.");
        Log::info("[WeeklyScore] bonus={$bonusCount} penalty={$penaltyCount} total={$total} week={$weekStart}");
    }
}
