<?php
namespace Modules\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;
use Modules\Order\Services\DispatchService;

class RetryDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $orderId) {}

    public function handle(DispatchService $dispatch): void
    {
        $order = Order::find($this->orderId);
        if (!$order || $order->status !== 'pending') return;

        // Nếu đang có offer chờ phản hồi (do lần quét trước đã tìm thấy ứng viên
        // sau khi job này được lên lịch) thì bỏ qua — chuỗi timeout của offer đó sẽ tự tiếp tục.
        $hasPendingOffer = OrderDispatchLog::where('order_id', $order->id)
            ->where('result', 'pending')
            ->exists();
        if ($hasPendingOffer) return;

        $dispatch->sendToNextDriver($order);
    }
}
