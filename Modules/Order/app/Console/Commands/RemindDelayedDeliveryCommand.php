<?php

namespace Modules\Order\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\FCMService;

class RemindDelayedDeliveryCommand extends Command
{
    protected $signature   = 'order:remind-delayed-delivery';
    protected $description = 'Nhắc tài xế bấm hoàn thành sau 15 phút đang giao';

    public function handle(): void
    {
        // processing là mốc đã lấy hàng và đang thực hiện đơn.
        $orders = DB::table('orders')
            ->join('users', 'orders.delivery_man_id', '=', 'users.id')
            ->where('orders.status', 'processing')
            ->where('orders.updated_at', '<', now()->subMinutes(15))
            ->whereNull('orders.delivery_reminder_sent_at')
            ->whereNotNull('users.fcm_token')
            ->select('orders.id', 'orders.code', 'users.fcm_token', 'users.name as driver_name')
            ->get();

        foreach ($orders as $order) {
            try {
                FCMService::getInstance()->sendDeliveryReminder($order->fcm_token, $order->code);
                DB::table('orders')->where('id', $order->id)
                    ->update(['delivery_reminder_sent_at' => now()]);
                $this->info("Nhắc đơn #{$order->code} → {$order->driver_name}");
            } catch (\Throwable $e) {
                $this->error("Lỗi đơn #{$order->code}: " . $e->getMessage());
            }
        }

        if ($orders->isEmpty()) {
            $this->line('Không có đơn nào cần nhắc.');
        }
    }
}
