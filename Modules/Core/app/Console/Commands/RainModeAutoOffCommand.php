<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\City;

/**
 * Tự tắt chế độ trời mưa sau 6 tiếng nếu admin/city_manager quên tắt tay —
 * tránh bật quên cả ngày gây tốn tiền thưởng oan và miễn phạt điểm quá lâu.
 */
class RainModeAutoOffCommand extends Command
{
    const MAX_HOURS = 6;

    protected $signature   = 'cities:rain-mode-auto-off';
    protected $description = "Tự tắt chế độ trời mưa cho thành phố đã bật quá " . self::MAX_HOURS . " tiếng";

    public function handle(): int
    {
        $cities = City::where('is_rain_mode', true)
            ->where('rain_mode_started_at', '<=', now()->subHours(self::MAX_HOURS))
            ->get();

        foreach ($cities as $city) {
            $city->update([
                'is_rain_mode'         => false,
                'rain_mode_started_at' => null,
                'rain_mode_by'         => null,
            ]);
            Log::info("[RainMode] Tự tắt chế độ trời mưa cho thành phố #{$city->id} {$city->name} — đã bật quá " . self::MAX_HOURS . " tiếng, có thể admin quên tắt.");
        }

        if ($cities->isNotEmpty()) {
            $this->info("Đã tự tắt trời mưa cho {$cities->count()} thành phố.");
        }

        return self::SUCCESS;
    }
}
