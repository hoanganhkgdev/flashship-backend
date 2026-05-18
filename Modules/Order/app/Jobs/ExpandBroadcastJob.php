<?php
namespace Modules\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Order\Models\Order;
use Modules\Order\Services\DispatchService;

class ExpandBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $orderId) {}

    public function handle(DispatchService $dispatch): void
    {
        $order = Order::find($this->orderId);
        if (!$order || $order->status !== 'pending') return;

        $dispatch->expandBroadcast($order);
    }
}
