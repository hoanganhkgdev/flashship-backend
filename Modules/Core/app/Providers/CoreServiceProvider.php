<?php

namespace Modules\Core\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Console\Commands\RainModeAutoOffCommand;

class CoreServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Core';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'core';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        RainModeAutoOffCommand::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules.
     *
     * @param $schedule
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        // Mỗi 15 phút — tự tắt trời mưa cho thành phố đã bật quá 6 tiếng
        // (đề phòng admin quên tắt tay).
        $schedule->command('cities:rain-mode-auto-off')->everyFifteenMinutes();
    }
}
