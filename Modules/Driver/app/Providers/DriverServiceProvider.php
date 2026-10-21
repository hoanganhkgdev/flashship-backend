<?php

namespace Modules\Driver\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Driver\Console\Commands\CheckShiftOfflineCommand;
use Modules\Driver\Console\Commands\GenerateDailyCommissionCommand;
use Modules\Driver\Console\Commands\GenerateWeeklyFeesCommand;
use Modules\Driver\Console\Commands\InactivityDecayCommand;
use Modules\Driver\Console\Commands\MarkOverdueDebtsCommand;
use Modules\Driver\Console\Commands\PruneDriverLocationLogsCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class DriverServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Driver';

    protected string $nameLower = 'driver';

    protected array $commands = [
        GenerateWeeklyFeesCommand::class,
        GenerateDailyCommissionCommand::class,
        InactivityDecayCommand::class,
        MarkOverdueDebtsCommand::class,
        PruneDriverLocationLogsCommand::class,
        CheckShiftOfflineCommand::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        // 23:59 hàng ngày — force-offline tài xế bị khoá nhưng vẫn online
        $schedule->command('drivers:daily-decay')->dailyAt('23:59');
        // Mỗi giờ — đánh dấu quá hạn nợ chưa đóng theo cấu hình vận hành.
        $schedule->command('driver:mark-overdue-debts')->hourly();
        // Thứ Hai 00:05 — tạo phí tuần mới
        $schedule->command('driver:generate-weekly-fees')->weeklyOn(1, '00:05');
        // 00:05 hàng ngày — tạo công nợ % phí ship của ngày hôm trước (khu vực chọn thu theo ngày)
        $schedule->command('driver:generate-daily-commission')->dailyAt('00:05');
        // Mỗi phút kiểm tra tài xế Offline quá 30 phút trong ca — phạt tự động.
        $schedule->command('drivers:check-shift-offline')
            ->everyMinute()
            ->withoutOverlapping();
    }
}
