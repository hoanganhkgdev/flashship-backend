<?php

namespace Modules\Order\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\OperationalSettings;

class RemindDelayedDeliveryCommand extends Command
{
    protected $signature   = 'order:remind-delayed-delivery';
    protected $description = 'Nhắc tài xế bấm hoàn thành khi đơn đang giao quá thời gian cấu hình';

    public function handle(): void
    {
        // processing là mốc đã lấy hàng và đang thực hiện đơn.
        $orders = DB::table('orders')
            ->join('users', 'orders.delivery_man_id', '=', 'users.id')
            ->where('orders.status', 'processing')
            ->whereNull('orders.delivery_reminder_sent_at')
            ->whereNotNull('users.fcm_token')
            ->select('orders.id', 'orders.code', 'orders.city_id', 'orders.updated_at', 'users.fcm_token', 'users.name as driver_name')
            ->get()
            // Mỗi khu vực một mốc nhắc riêng — lọc sau khi lấy (ít đơn đang giao).
            ->filter(fn ($order) => Carbon::parse($order->updated_at)
                ->lt(now()->subMinutes(OperationalSettings::delayedReminderMinutes($order->city_id))));

        foreach ($orders as $order) {
            try {
                // Hai cron nhắc trễ và tự hoàn thành có thể cùng nhìn thấy
                // reminder_sent_at=NULL trong một phút. Claim trước bằng
                // update có điều kiện để một đơn chỉ phát đúng một thông báo.
                $claimed = DB::table('orders')
                    ->where('id', $order->id)
                    ->whereNull('delivery_reminder_sent_at')
                    ->update(['delivery_reminder_sent_at' => now()]);
                if (! $claimed) {
                    continue;
                }

                FCMService::getInstance()->sendDeliveryReminder($order->fcm_token, $order->code);
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
