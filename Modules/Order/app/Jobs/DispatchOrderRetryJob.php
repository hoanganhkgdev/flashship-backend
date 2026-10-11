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
use Illuminate\Support\Facades\Redis;

class DispatchOrderRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 15];

    public function __construct(public readonly int $orderId) {}

    public function handle(DispatchService $dispatch): void
    {
        // Xóa flag để cycle tiếp theo có thể schedule retry mới
        Redis::del("dispatch:retry_pending:{$this->orderId}");

        $order = Order::find($this->orderId);

        if (!$order || $order->status !== 'pending') {
            Log::info("[Dispatch] Retry #{$this->orderId}: không còn pending, skip");
            return;
        }

        Log::info("╟── [Dispatch] Đơn #{$order->id}: Retry quét lại toàn thành phố");
        // offerToNext() là nơi duy nhất quyết định hết hạn. Nếu vẫn còn offer
        // mở, tài xế được dùng hết thời gian phản hồi dù job retry chạy đúng
        // lúc chạm hạn tổng.
        $dispatch->offerToNext($order);
    }
}
