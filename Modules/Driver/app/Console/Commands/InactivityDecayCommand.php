<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InactivityDecayCommand extends Command
{
    protected $signature   = 'drivers:daily-decay';
    protected $description = 'Cuối ngày (23:59): reset bộ đếm giờ online hiển thị, force-offline tài xế bị khoá';

    public function handle(): void
    {
        $today = Carbon::today()->toDateString();

        // Force offline tài xế bị khoá nhưng vẫn online
        DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', '!=', 1)
            ->where('is_online', true)
            ->update(['is_online' => false, 'online_since' => null]);

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->whereDate('created_at', '<', $today)
            ->select('id', 'is_online')
            ->get();

        foreach ($drivers as $driver) {
            // Reset bộ đếm giờ online hiển thị (DriverEarningsPage) — ca đêm
            // 00:01-06:30 sẽ đếm riêng. Luật chấm điểm theo giờ online/không
            // hoạt động đã chuyển hẳn sang ScoreShiftSessionsCommand (theo
            // từng ca đăng ký, không còn theo mốc 23:59 cố định này nữa —
            // không đăng ký ca đã coi là nghỉ, không phạt).
            DB::table('users')->where('id', $driver->id)->update([
                'daily_online_seconds' => 0,
                'daily_online_date'    => null,
            ]);

            if ($driver->is_online) {
                DB::table('users')->where('id', $driver->id)->update([
                    'online_since' => now(),
                ]);
            }
        }

        $total = $drivers->count();
        $this->info("[DailyDecay] {$total} tài xế đã reset bộ đếm giờ online.");
        Log::info("[DailyDecay] total={$total}");
    }
}
