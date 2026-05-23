<?php

namespace Modules\Driver\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Driver\Console\Commands\DecayDriverScoresCommand;
use Modules\Driver\Console\Commands\GenerateWeeklyFeesCommand;
use Modules\Driver\Console\Commands\MarkOverdueDebtsCommand;

class DriverServiceProvider extends ModuleServiceProvider
{
    protected string $name      = 'Driver';
    protected string $nameLower = 'driver';

    protected array $commands = [
        DecayDriverScoresCommand::class,
        GenerateWeeklyFeesCommand::class,
        MarkOverdueDebtsCommand::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        // Chủ nhật 13:00 — khóa tài xế chưa đóng phí tuần
        $schedule->command('driver:mark-overdue-debts')->weeklyOn(0, '13:00');
        // Thứ Hai 08:00 — tạo phí tuần mới cho tất cả tài xế
        $schedule->command('driver:generate-weekly-fees')->weeklyOn(1, '08:00');
        // Hàng ngày 03:00 — decay điểm tài xế không hoạt động
        $schedule->command('drivers:decay-scores')->dailyAt('03:00');
    }
}
