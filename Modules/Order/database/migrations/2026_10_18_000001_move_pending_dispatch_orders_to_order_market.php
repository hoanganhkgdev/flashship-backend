<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hệ thống tự gán đơn (offer theo wave) đã bị gỡ; Chợ đơn là đường phát đơn
 * duy nhất. Dọn nốt dữ liệu đang chạy dở lúc deploy: đóng mọi offer còn
 * pending và đưa các đơn đang được hệ thống tìm tài xế vào Chợ đơn để không
 * bị kẹt ở trạng thái chờ mà không còn job nào xử lý.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('order_dispatch_logs')->where('result', 'pending')->update([
            'result' => 'expired',
            'responded_at' => $now,
            'close_reason' => 'stale_recovery',
            'updated_at' => $now,
        ]);

        $stuck = DB::table('orders')
            ->where('status', 'pending')
            ->whereNotNull('city_id')
            ->whereNotNull('dispatch_started_at')
            ->where(fn ($query) => $query->whereNull('cancel_reason')->orWhere('cancel_reason', '!=', 'no_driver'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('order_market_listings')
                ->whereColumn('order_market_listings.order_id', 'orders.id')
                ->where('order_market_listings.status', 'open'))
            ->get(['id', 'city_id', 'dispatch_attempts']);

        foreach ($stuck as $order) {
            $values = [
                'city_id' => $order->city_id,
                'status' => 'open',
                'open_reason' => 'self_pick',
                'offer_attempts' => (int) $order->dispatch_attempts,
                'opened_at' => $now,
                'expires_at' => $now->copy()->addDay(), // migration kế tiếp bỏ hạn
                'claimed_at' => null,
                'claimed_by' => null,
                'updated_at' => $now,
            ];
            $updated = DB::table('order_market_listings')->where('order_id', $order->id)->update($values);
            if (! $updated) {
                DB::table('order_market_listings')->insert($values + ['order_id' => $order->id, 'created_at' => $now]);
            }
            DB::table('order_histories')->insert([
                'order_id' => $order->id,
                'type' => 'market_opened',
                'description' => 'Đưa vào Chợ đơn khi hệ thống gán đơn tự động được gỡ',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('orders')->where('status', 'pending')->update([
            'dispatch_mode' => 'self_pick',
            'dispatching_to_driver_id' => null,
        ]);
    }

    public function down(): void
    {
        // Dữ liệu offer đã đóng không thể khôi phục.
    }
};
