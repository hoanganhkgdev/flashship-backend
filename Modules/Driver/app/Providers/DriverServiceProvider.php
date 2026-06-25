<?php

namespace Modules\Driver\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Driver\Console\Commands\GenerateWeeklyFeesCommand;
use Modules\Driver\Console\Commands\InactivityDecayCommand;
use Modules\Driver\Console\Commands\MarkOverdueDebtsCommand;
use Modules\Driver\Console\Commands\SyncDriverGeoCommand;
use Modules\Driver\Console\Commands\WeeklyScoreCommand;
use Modules\Driver\Console\Commands\ResetDailyOnlineCommand;

class DriverServiceProvider extends ModuleServiceProvider
{
    protected string $name      = 'Driver';
    protected string $nameLower = 'driver';

    protected array $commands = [
        GenerateWeeklyFeesCommand::class,
        InactivityDecayCommand::class,
        MarkOverdueDebtsCommand::class,
        SyncDriverGeoCommand::class,
        WeeklyScoreCommand::class,
        ResetDailyOnlineCommand::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        // Mỗi 5 phút — sync vị trí tài xế online từ DB vào Redis GEO (tự phục hồi khi Redis restart)
        $schedule->command('driver:sync-geo')->everyFiveMinutes();
        // 00:00 hàng ngày — xét online 6:30-00:00 có đủ 8h không, trừ điểm, reset time
        $schedule->command('drivers:daily-decay')->dailyAt('00:00');
        // 06:30 hàng ngày — reset time online, bắt đầu tính ngày mới
        $schedule->command('driver:reset-daily-online')->dailyAt('06:30');
        // Thứ Hai 00:02 — chốt điểm tuần (sau daily-decay 00:00)
        $schedule->command('drivers:weekly-score')->weeklyOn(1, '00:02');
        // Mỗi giờ — đánh dấu quá hạn nợ chưa đóng sau 24 tiếng
        $schedule->command('driver:mark-overdue-debts')->hourly();
        // Thứ Hai 00:05 — tạo phí tuần mới (sau weekly-score)
        $schedule->command('driver:generate-weekly-fees')->weeklyOn(1, '00:05');
    }
}
