<?php
namespace Modules\Driver\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Driver\Models\DriverLocationLog;

class PruneDriverLocationLogsCommand extends Command
{
    protected $signature   = 'driver:prune-location-logs';
    protected $description = 'Xoá log lịch sử GPS tài xế cũ hơn 30 ngày';

    public function handle(): int
    {
        $cutoff  = Carbon::now()->subDays(30);
        $deleted = DriverLocationLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Đã xoá {$deleted} bản ghi GPS cũ hơn {$cutoff->toDateString()}.");
        Log::info("[PruneLocationLogs] Deleted {$deleted} rows older than {$cutoff->toDateString()}");

        return self::SUCCESS;
    }
}
