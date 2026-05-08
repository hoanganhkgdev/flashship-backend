<?php

namespace Modules\Order\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

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
        \Modules\Order\Console\Commands\AutoCancelPendingOrders::class,
        \Modules\Order\Console\Commands\DispatchScheduledOrders::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('orders:auto-cancel-pending')->everyMinute();
        $schedule->command('orders:dispatch-scheduled')->everyMinute();
    }
}
