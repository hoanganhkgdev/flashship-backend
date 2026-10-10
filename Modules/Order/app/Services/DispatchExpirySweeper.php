<?php

namespace Modules\Order\Services;

use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDispatchLog;

/**
 * Expire offers by their persisted deadline instead of relying exclusively on
 * delayed queue jobs, whose execution can drift when the shared queue is busy.
 */
class DispatchExpirySweeper
{
    public function __construct(private readonly DispatchService $dispatch) {}

    public function sweep(int $limit = 100): int
    {
        $dueOffers = OrderDispatchLog::query()
            ->where('result', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get(['id', 'order_id', 'driver_id', 'expires_at']);

        $handled = 0;
        foreach ($dueOffers as $offer) {
            $order = Order::find($offer->order_id);
            if (! $order || $order->status !== 'pending'
                || (int) $order->dispatching_to_driver_id !== (int) $offer->driver_id) {
                continue;
            }

            $this->dispatch->handleTimeout($order, (int) $offer->driver_id);
            $handled++;
        }

        return $handled;
    }
}
