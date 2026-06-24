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

class DispatchOrderRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $orderId) {}

    public function handle(DispatchService $dispatch): void
    {
        $order = Order::find($this->orderId);

        if (!$order || $order->status !== 'pending') {
            Log::info("[Dispatch] Retry #{$this->orderId}: không còn pending, skip");
            return;
        }

        Log::info("╟── [Dispatch] Đơn #{$order->id}: Retry quét lại từ đầu");
        $dispatch->buildQueueAndSend($order, DispatchService::RADIUS_KM_STAGES[0]);
    }
}
