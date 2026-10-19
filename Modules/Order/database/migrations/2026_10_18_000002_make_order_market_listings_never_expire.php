<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đơn trên danh sách đơn mới không còn hết hạn: nằm đó đến khi có tài xế nhận,
 * tổng đài gán tay hoặc bị huỷ. Mở lại các listing đã hết hạn của đơn còn pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_market_listings', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
        });

        $now = now();
        DB::table('order_market_listings')->where('status', 'open')->update(['expires_at' => null, 'updated_at' => $now]);
        DB::table('order_market_listings')
            ->whereIn('status', ['expired', 'cancelled'])
            ->whereIn('order_id', DB::table('orders')->where('status', 'pending')->select('id'))
            ->update(['status' => 'open', 'expires_at' => null, 'opened_at' => $now, 'updated_at' => $now]);

        // Đơn pending chưa từng có listing (đơn cũ của luồng gán tự động đã dừng tìm).
        $missing = DB::table('orders')
            ->where('status', 'pending')->whereNotNull('city_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_market_listings as l')->whereColumn('l.order_id', 'orders.id'))
            ->get(['id', 'city_id', 'dispatch_attempts']);
        foreach ($missing as $order) {
            DB::table('order_market_listings')->insert([
                'order_id' => $order->id, 'city_id' => $order->city_id, 'status' => 'open', 'open_reason' => 'self_pick',
                'offer_attempts' => (int) $order->dispatch_attempts, 'opened_at' => $now, 'expires_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('orders')->where('status', 'pending')->where('cancel_reason', 'no_driver')
            ->update(['cancel_reason' => null, 'updated_at' => $now]);
    }

    public function down(): void
    {
        DB::table('order_market_listings')->whereNull('expires_at')->update(['expires_at' => now()]);
        Schema::table('order_market_listings', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
