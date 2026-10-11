<?php

namespace Modules\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchService;
use Modules\Core\Services\OperationalSettings;

class CheckDriverOfferReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [3, 5];

    public function __construct(
        public readonly int $orderId,
        public readonly int $driverId,
    ) {}

    public function handle(DispatchService $dispatch): void
    {
        $order = Order::find($this->orderId);
        if (! $order || $order->status !== 'pending') {
            return;
        }

        $received = OrderDispatchLog::where('order_id', $this->orderId)
            ->where('driver_id', $this->driverId)
            ->where('result', 'pending')
            ->whereNotNull('received_at')
            ->exists();

        if ($received) {
            return;
        }

        $receiptSeconds = OperationalSettings::offerReceiptSeconds($order->city_id);
        Log::info("[Dispatch] Đơn #{$this->orderId}: sau {$receiptSeconds} giây thiết bị tài xế #{$this->driverId} chưa ACK → chuyển người kế");
        $dispatch->handleTimeout($order, $this->driverId);
    }
}
