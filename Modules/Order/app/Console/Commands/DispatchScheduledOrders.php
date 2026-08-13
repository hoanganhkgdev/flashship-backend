<?php
namespace Modules\Order\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Order\Models\Order;
use Modules\Order\Services\DispatchService;

class DispatchScheduledOrders extends Command
{
    protected $signature   = 'orders:dispatch-scheduled';
    protected $description = 'Bắt đầu tìm tài xế cho các đơn đặt lịch đến giờ';

    public function handle(): void
    {
        $orders = Order::where('status', 'pending')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', Carbon::now())
            ->where('dispatch_attempts', 0)
            ->get();

        if ($orders->isEmpty()) return;

        $dispatch = app(DispatchService::class);

        foreach ($orders as $order) {
            $dispatch->startDispatch($order);
            $this->line("Dispatch đơn lịch {$order->code} (scheduled_at: {$order->scheduled_at})");
        }

        $this->info("Đã dispatch {$orders->count()} đơn lịch.");
    }
}
