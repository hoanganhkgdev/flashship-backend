<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\City;
use Modules\Core\Services\OperationalSettings;

/**
 * Tự tắt chế độ trời mưa theo thời gian cấu hình nếu admin/city_manager quên tắt tay —
 * tránh bật quên cả ngày gây tốn tiền thưởng oan và miễn phạt điểm quá lâu.
 */
class RainModeAutoOffCommand extends Command
{
    protected $signature   = 'cities:rain-mode-auto-off';
    protected $description = 'Tự tắt chế độ trời mưa khi đã bật quá thời gian cấu hình';

    public function handle(): int
    {
        $maxHours = OperationalSettings::rainModeAutoOffHours();
        $cities = City::where('is_rain_mode', true)
            ->where('rain_mode_started_at', '<=', now()->subHours($maxHours))
            ->get();

        foreach ($cities as $city) {
            $city->update([
                'is_rain_mode'         => false,
                'rain_mode_started_at' => null,
                'rain_mode_by'         => null,
            ]);
            Log::info("[RainMode] Tự tắt chế độ trời mưa cho thành phố #{$city->id} {$city->name} — đã bật quá {$maxHours} tiếng, có thể admin quên tắt.");
        }

        if ($cities->isNotEmpty()) {
            $this->info("Đã tự tắt trời mưa cho {$cities->count()} thành phố.");
        }

        return self::SUCCESS;
    }
}
