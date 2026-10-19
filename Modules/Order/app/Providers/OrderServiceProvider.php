<?php

namespace Modules\Order\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Order\Console\Commands\PruneStaleRtdbOrdersCommand;
use Modules\Order\Console\Commands\AutoCompleteArrivedOrdersCommand;
use Modules\Order\Console\Commands\SyncMarketToFirebaseCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class OrderServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Order';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'order';

    protected array $commands = [
        \Modules\Order\Console\Commands\RemindDelayedDeliveryCommand::class,
        PruneStaleRtdbOrdersCommand::class,
        AutoCompleteArrivedOrdersCommand::class,
        SyncMarketToFirebaseCommand::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('orders:prune-stale-rtdb')
            ->everyTenMinutes()
            ->withoutOverlapping();
        // Lưới an toàn phía server: sửa lệch nếu một lần ghi Firebase bị lỗi giữa chừng.
        $schedule->command('market:sync-firebase')
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command('order:remind-delayed-delivery')->everyMinute();
        $schedule->command('orders:auto-complete-arrived')
            ->everyMinute()
            ->withoutOverlapping();
    }
}
