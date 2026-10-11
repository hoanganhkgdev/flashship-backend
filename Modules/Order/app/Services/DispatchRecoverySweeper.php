<?php

namespace Modules\Order\Services;

use Illuminate\Support\Facades\Redis;
use Modules\Order\Models\Order;

/**
 * Lưới an toàn cho đơn hệ thống bị rơi giữa các delayed job/worker restart.
 * Chỉ khôi phục đơn đã bắt đầu phát nhưng hiện không còn offer đang mở.
 */
class DispatchRecoverySweeper
{
    public function __construct(private readonly DispatchService $dispatch) {}

    public function sweep(int $limit = 100): int
    {
        $orders = Order::query()
            ->where('status', 'pending')
            ->whereNotNull('dispatch_started_at')
            ->where(fn ($query) => $query->whereNull('dispatch_mode')->orWhere('dispatch_mode', 'system_assigned'))
            ->where(fn ($query) => $query->whereNull('cancel_reason')->orWhere('cancel_reason', '!=', 'no_driver'))
            ->whereDoesntHave('marketListing', fn ($query) => $query->where('status', 'open'))
            ->whereDoesntHave('dispatchLogs', fn ($query) => $query->where('result', 'pending'))
            ->orderBy('dispatch_started_at')
            ->limit($limit)
            ->get();

        $handled = 0;
        foreach ($orders as $order) {
            // Retry bình thường đã được lên lịch thì tôn trọng nhịp cấu hình;
            // chỉ can thiệp khi không còn cờ (job mất, worker restart hoặc
            // luồng timeout thua tranh chấp khóa trước khi kịp schedule).
            if (Redis::exists("dispatch:retry_pending:{$order->id}")) {
                continue;
            }
            $this->dispatch->offerToNext($order);
            $handled++;
        }

        return $handled;
    }
}
