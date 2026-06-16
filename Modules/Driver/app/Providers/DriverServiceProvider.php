<?php

namespace Modules\Driver\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Driver\Console\Commands\GenerateWeeklyFeesCommand;
use Modules\Driver\Console\Commands\MarkOverdueDebtsCommand;
use Modules\Driver\Console\Commands\WeeklyScoreCommand;

class DriverServiceProvider extends ModuleServiceProvider
{
    protected string $name      = 'Driver';
    protected string $nameLower = 'driver';

    protected array $commands = [
        GenerateWeeklyFeesCommand::class,
        MarkOverdueDebtsCommand::class,
        WeeklyScoreCommand::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        // Chủ nhật 23:50 — chốt điểm tuần (thưởng/phạt 50k) trước khi reset
        $schedule->command('drivers:weekly-score')->weeklyOn(0, '23:50');
        // Chủ nhật 13:00 — khóa tài xế chưa đóng phí tuần
        $schedule->command('driver:mark-overdue-debts')->weeklyOn(0, '13:00');
        // Thứ Hai 00:00 — tạo phí tuần mới (chạy sau khi reset điểm)
        $schedule->command('driver:generate-weekly-fees')->weeklyOn(1, '00:05');
    }
}
