<?php
namespace Modules\Driver\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Services\DriverScoreService;

class DecayDriverScoresCommand extends Command
{
    protected $signature   = 'drivers:decay-scores';
    protected $description = 'Giảm điểm tài xế không hoạt động trong 7+ ngày';

    // Tài xế không hoàn thành đơn nào trong 7 ngày: -2 điểm
    const DAYS_MILD   = 7;
    const DECAY_MILD  = -2;

    // Tài xế không hoàn thành đơn nào trong 14 ngày: -5 điểm
    const DAYS_SEVERE  = 14;
    const DECAY_SEVERE = -5;

    public function handle(): void
    {
        $now = now();

        // Lấy ngày hoàn thành đơn gần nhất của mỗi tài xế
        $lastCompleted = DB::table('orders')
            ->where('status', 'completed')
            ->whereNotNull('delivery_man_id')
            ->select('delivery_man_id', DB::raw('MAX(completed_at) as last_completed_at'))
            ->groupBy('delivery_man_id')
            ->pluck('last_completed_at', 'delivery_man_id');

        $drivers = DB::table('users')
            ->where('user_type', 'driver')
            ->where('status', 1)
            ->select('id', 'name', 'driver_score')
            ->get();

        $mild   = 0;
        $severe = 0;

        foreach ($drivers as $driver) {
            $lastAt = $lastCompleted[$driver->id] ?? null;

            if (!$lastAt) {
                // Chưa hoàn thành đơn nào → xem như inactive từ lúc tạo tài khoản
                $daysSince = PHP_INT_MAX;
            } else {
                $daysSince = $now->diffInDays(\Carbon\Carbon::parse($lastAt));
            }

            if ($daysSince >= self::DAYS_SEVERE) {
                DriverScoreService::onDecay($driver->id, self::DECAY_SEVERE, 'inactivity_14d');
                $severe++;
            } elseif ($daysSince >= self::DAYS_MILD) {
                DriverScoreService::onDecay($driver->id, self::DECAY_MILD, 'inactivity_7d');
                $mild++;
            }
        }

        $this->info("[DecayDriverScores] Hoàn tất: {$mild} tài xế -2đ (7d), {$severe} tài xế -5đ (14d)");
    }
}
