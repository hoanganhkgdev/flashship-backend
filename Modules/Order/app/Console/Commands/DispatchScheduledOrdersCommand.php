<?php
namespace Modules\Order\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\Order\Models\Order;
use Modules\Order\Services\DispatchService;

class DispatchScheduledOrdersCommand extends Command
{
    protected $signature = 'orders:dispatch-scheduled';
    protected $description = 'Phát các đơn hẹn giờ đã đến thời điểm';

    public function handle(DispatchService $dispatch): int
    {
        Order::where('status', 'pending')
            ->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now())
            ->whereNull('dispatch_started_at')->orderBy('scheduled_at')
            ->chunkById(100, function ($orders) use ($dispatch) {
                foreach ($orders as $order) {
                    $lock = Cache::lock("scheduled-dispatch:{$order->id}", 55);
                    if (!$lock->get()) continue;
                    try {
                        $fresh = $order->fresh();
                        if ($fresh?->status === 'pending' && !$fresh->dispatch_started_at
                            && $fresh->scheduled_at?->lessThanOrEqualTo(now())) {
                            $dispatch->startDispatch($fresh);
                        }
                    } finally {
                        $lock->release();
                    }
                }
            });
        return self::SUCCESS;
    }
}
