<?php
namespace Modules\Order\Jobs;

use Modules\Order\Models\Order;
use Modules\Order\Services\DispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $orderId,
        public readonly int $driverId,
    ) {}

    public function handle(DispatchService $dispatch): void
    {
        $order = Order::find($this->orderId);

        if (!$order) {
            Log::info("[Dispatch] Job #{$this->orderId}: order not found, skip");
            return;
        }

        if ($order->status !== 'pending' || (int) $order->dispatching_to_driver_id !== $this->driverId) {
            Log::info("[Dispatch] Job #{$this->orderId}: already handled (status={$order->status}), skip");
            return;
        }

        Log::info("[Dispatch] Job: offer to driver #{$this->driverId} for order #{$this->orderId} expired → trying next");
        $dispatch->handleTimeout($order, $this->driverId);
    }
}
