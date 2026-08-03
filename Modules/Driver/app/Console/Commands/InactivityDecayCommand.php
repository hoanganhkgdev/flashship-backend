<?php
namespace Modules\Driver\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InactivityDecayCommand extends Command
{
    protected $signature   = 'drivers:daily-decay';
    protected $description = 'Cuối ngày (23:59): force-offline tài xế bị khoá nhưng vẫn online';

    public function handle(): void
    {
        // Luật chấm điểm theo giờ online/không hoạt động đã chuyển hẳn sang
        // ScoreShiftSessionsCommand (theo từng ca đăng ký, không còn theo mốc
        // 23:59 cố định này nữa — không đăng ký ca đã coi là nghỉ, không phạt).
        // Việc còn lại của lệnh này: đảm bảo tài xế bị khoá không kẹt ở
        // trạng thái online (VD bị khoá ngay lúc đang chạy đơn).
        $updated = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', '!=', 1)
            ->where('is_online', true)
            ->update(['is_online' => false, 'online_since' => null]);

        $this->info("[DailyDecay] Force-offline {$updated} tài xế bị khoá.");
        Log::info("[DailyDecay] Force-offline {$updated} tài xế bị khoá.");
    }
}
