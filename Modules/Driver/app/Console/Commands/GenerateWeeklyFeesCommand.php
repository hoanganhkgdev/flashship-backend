<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverDebt;

class GenerateWeeklyFeesCommand extends Command
{
    protected $signature   = 'driver:generate-weekly-fees {--week= : Ngày bắt đầu tuần (YYYY-MM-DD), mặc định tuần hiện tại}';
    protected $description = 'Tạo phí tuần cho tài xế theo từng khu vực';

    public function handle(): int
    {
        $weekStart = $this->option('week')
            ? Carbon::parse($this->option('week'))->startOfWeek()
            : Carbon::now()->startOfWeek();

        $weekEnd = $weekStart->copy()->endOfWeek();

        $this->info("Tạo phí tuần: {$weekStart->toDateString()} — {$weekEnd->toDateString()}");

        $cities = City::active()->where('weekly_fee', '>', 0)->get();

        if ($cities->isEmpty()) {
            $this->warn('Không có khu vực nào cấu hình phí tuần.');
            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;

        foreach ($cities as $city) {
            $drivers = User::where('user_type', 'driver')
                ->where('city_id', $city->id)
                ->where('status', 1)
                ->pluck('id');

            if ($drivers->isEmpty()) continue;

            $existing = DriverDebt::where('week_start', $weekStart->toDateString())
                ->whereIn('driver_id', $drivers)
                ->pluck('driver_id')
                ->flip();

            $rows = [];
            $now  = now()->toDateTimeString();

            foreach ($drivers as $driverId) {
                if ($existing->has($driverId)) {
                    $skipped++;
                    continue;
                }

                $rows[] = [
                    'driver_id'   => $driverId,
                    'status'      => 'pending',
                    'amount_due'  => $city->weekly_fee,
                    'amount_paid' => 0,
                    'week_start'  => $weekStart->toDateString(),
                    'week_end'    => $weekEnd->toDateString(),
                    'note'        => "Phí tuần {$weekStart->format('d/m')} – {$weekEnd->format('d/m/Y')} ({$city->name})",
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
                $created++;
            }

            if (!empty($rows)) {
                DB::table('driver_debts')->insert($rows);
            }

            $this->line("  [{$city->name}] phí {$city->weekly_fee} đ — tạo {$created} bản ghi");
        }

        $this->info("Hoàn thành: tạo {$created}, bỏ qua (đã có) {$skipped}");
        Log::info("[WeeklyFees] Tuần {$weekStart->toDateString()}: tạo={$created}, skip={$skipped}");

        return self::SUCCESS;
    }
}
