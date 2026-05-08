<?php
namespace Modules\Order\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Core\Services\FCMService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderHistory;

class AutoCancelPendingOrders extends Command
{
    protected $signature   = 'orders:auto-cancel-pending';
    protected $description = 'Huỷ các đơn pending quá 10 phút không có tài xế nhận';

    public function handle(): void
    {
        $cutoff = Carbon::now()->subMinutes(10);

        $orders = Order::where('status', 'pending')
            ->where(function ($q) use ($cutoff) {
                // Non-scheduled: cancel 10 min after created_at
                $q->whereNull('scheduled_at')->where('created_at', '<=', $cutoff);
                // Scheduled but already dispatched: cancel 10 min after scheduled_at
                $q->orWhere(function ($q2) use ($cutoff) {
                    $q2->whereNotNull('scheduled_at')
                        ->where('scheduled_at', '<=', $cutoff)
                        ->where('dispatch_attempts', '>', 0);
                });
            })
            ->with('sender')
            ->get();

        if ($orders->isEmpty()) return;

        $fcm = FCMService::getInstance();

        foreach ($orders as $order) {
            $order->update([
                'status'        => 'cancelled',
                'cancel_reason' => 'no_driver',
            ]);

            OrderHistory::create([
                'order_id'    => $order->id,
                'type'        => 'auto_cancelled',
                'description' => 'Hệ thống tự huỷ: không tìm được tài xế sau 10 phút',
            ]);

            // Push FCM đến khách hàng
            $customer = $order->sender;
            if ($customer && $customer->fcm_token) {
                $fcm->sendNoDriverCancellation($customer->fcm_token, $order->code);
            }

            $this->line("Huỷ đơn {$order->code} (no_driver)");
        }

        $this->info("Đã huỷ {$orders->count()} đơn hàng.");
    }
}
