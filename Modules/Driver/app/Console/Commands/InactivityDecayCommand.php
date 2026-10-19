<?php

namespace Modules\Driver\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Driver\Models\DriverShiftSession;

class InactivityDecayCommand extends Command
{
    protected $signature = 'drivers:daily-decay';

    protected $description = 'Cuối ngày (23:59): force-offline tài xế bị khoá nhưng vẫn online';

    public function handle(): void
    {
        // Đảm bảo tài xế bị khoá không kẹt ở trạng thái online
        // (VD bị khoá ngay lúc đang chạy đơn).
        $updated = DB::transaction(function () {
            $now = now();
            $driverIds = DB::table('users')
                ->where('user_type', 'driver')
                ->where('status', '!=', 1)
                ->where('is_online', true)
                ->lockForUpdate()
                ->pluck('id');

            DriverShiftSession::whereIn('driver_id', $driverIds)
                ->whereNull('ended_at')
                ->update(['ended_at' => $now]);

            return DB::table('users')
                ->whereIn('id', $driverIds)
                ->update(['is_online' => false, 'online_since' => null]);
        });

        $this->info("[DailyDecay] Force-offline {$updated} tài xế bị khoá.");
        Log::info("[DailyDecay] Force-offline {$updated} tài xế bị khoá.");
    }
}
