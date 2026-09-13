<?php

namespace Modules\Driver\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Driver\Console\Commands\GenerateWeeklyFeesCommand;
use Modules\Driver\Console\Commands\InactivityDecayCommand;
use Modules\Driver\Console\Commands\MarkOverdueDebtsCommand;
use Modules\Driver\Console\Commands\PruneDriverLocationLogsCommand;
use Modules\Driver\Console\Commands\ScoreShiftSessionsCommand;
use Modules\Driver\Console\Commands\TrackGpsEligibleSessionsCommand;
use Modules\Driver\Console\Commands\WeeklyScoreCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class DriverServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Driver';

    protected string $nameLower = 'driver';

    protected array $commands = [
        GenerateWeeklyFeesCommand::class,
        InactivityDecayCommand::class,
        MarkOverdueDebtsCommand::class,
        WeeklyScoreCommand::class,
        PruneDriverLocationLogsCommand::class,
        ScoreShiftSessionsCommand::class,
        TrackGpsEligibleSessionsCommand::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        // 23:59 hàng ngày — force-offline tài xế bị khoá nhưng vẫn online
        $schedule->command('drivers:daily-decay')->dailyAt('23:59');
        // Thứ Hai 00:02 — chốt điểm tuần (sau daily-decay 23:59)
        $schedule->command('drivers:weekly-score')->weeklyOn(1, '00:02');
        // Mỗi giờ — đánh dấu quá hạn nợ chưa đóng sau 24 tiếng
        $schedule->command('driver:mark-overdue-debts')->hourly();
        // Thứ Hai 00:05 — tạo phí tuần mới (sau weekly-score)
        $schedule->command('driver:generate-weekly-fees')->weeklyOn(1, '00:05');
        // Mỗi 5 phút — dò ca vừa kết thúc (giờ khác nhau theo từng khu vực),
        // chấm % thời gian online cuối ca — thay luật "8h/ngày" cũ.
        $schedule->command('drivers:score-shift-sessions')->everyFiveMinutes();
        // GPS chỉ còn phục vụ phát đơn. Chấm giờ ca dùng các phiên Online /
        // Offline trong driver_shift_sessions, nên không chạy tracker GPS.
    }
}
