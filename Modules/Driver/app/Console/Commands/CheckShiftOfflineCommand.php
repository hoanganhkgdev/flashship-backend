<?php

namespace Modules\Driver\Console\Commands;

use Illuminate\Console\Command;
use Modules\Driver\Services\ShiftOfflineService;

class CheckShiftOfflineCommand extends Command
{
    protected $signature = 'drivers:check-shift-offline';

    protected $description = 'Phạt tài xế Offline quá 30 phút trong ca đã đăng ký';

    public function handle(ShiftOfflineService $service): int
    {
        $count = $service->check();
        $this->info("Đã phạt {$count} tài xế Offline quá giờ.");

        return self::SUCCESS;
    }
}
