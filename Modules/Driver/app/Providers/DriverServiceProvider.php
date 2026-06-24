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
        // 06:00 hàng ngày — reset thời gian online trước ca mới
        $schedule->command('driver:reset-daily-online')->dailyAt('06:00');
        // Hàng ngày 23:45 — trừ điểm tài xế online < 8h và không hoạt động (phải trước weekly-score)
        $schedule->command('drivers:daily-decay')->dailyAt('23:45');
        // Chủ nhật 23:50 — chốt điểm tuần (thưởng/phạt 50k vào ví) rồi reset về 100
        $schedule->command('drivers:weekly-score')->weeklyOn(0, '23:50');
        // Chủ nhật 13:00 — khóa tài xế chưa đóng phí tuần
        $schedule->command('driver:mark-overdue-debts')->weeklyOn(0, '13:00');
        // Thứ Hai 00:00 — tạo phí tuần mới (chạy sau khi reset điểm)
        $schedule->command('driver:generate-weekly-fees')->weeklyOn(1, '00:05');
    }
}
