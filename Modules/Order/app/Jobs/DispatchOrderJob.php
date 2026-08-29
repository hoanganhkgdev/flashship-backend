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

    public int $tries = 3;
    public array $backoff = [5, 15];

    public function __construct(
        public readonly int  $orderId,
        public readonly int  $driverId,
        public readonly bool $isAppDecision = false,
    ) {}

    public function handle(DispatchService $dispatch): void
    {
        $order = Order::find($this->orderId);

        if (!$order) return;

        if ($order->status !== 'pending' || (int) $order->dispatching_to_driver_id !== $this->driverId) {
            Log::info("[Dispatch] Job #{$this->orderId}: already handled (status={$order->status}), skip");
            return;
        }

        if (!$this->isAppDecision && $order->offer_viewed_at !== null) {
            // Driver opened the app — the app-decision job handles the timeout, skip this one.
            Log::info("[Dispatch] Job #{$this->orderId}: driver opened app, callkit-timeout job skipped");
            return;
        }

        Log::info("[Dispatch] Job: offer to driver #{$this->driverId} for order #{$this->orderId} expired → trying next");
        $dispatch->handleTimeout($order, $this->driverId);
    }
}
