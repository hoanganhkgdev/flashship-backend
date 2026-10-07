<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('driver_order_role', 20)->nullable()->after('delivery_man_id');
            $table->foreignId('main_order_id')->nullable()->after('driver_order_role')->constrained('orders')->nullOnDelete();
            $table->unsignedTinyInteger('extra_claimed_count')->default(0)->after('main_order_id');
            $table->timestamp('bundle_window_expires_at')->nullable()->after('extra_claimed_count');
            $table->index(['delivery_man_id', 'driver_order_role', 'status'], 'orders_driver_role_status_index');
        });

        // Giữ nguyên phiên test đang chạy khi deploy: đơn active không đi
        // qua Chợ là đơn chính; các đơn market đã claim là đơn phụ.
        $driverIds = DB::table('orders')
            ->whereIn('status', ['assigned', 'processing'])
            ->whereNotNull('delivery_man_id')
            ->distinct()->pluck('delivery_man_id');

        foreach ($driverIds as $driverId) {
            $activeIds = DB::table('orders')->where('delivery_man_id', $driverId)
                ->whereIn('status', ['assigned', 'processing'])->orderBy('id')->pluck('id');
            $extraIds = DB::table('order_market_listings')->where('claimed_by', $driverId)
                ->where('status', 'claimed')->whereIn('order_id', $activeIds)->pluck('order_id');
            $mainId = $activeIds->first(fn ($id) => ! $extraIds->contains($id));

            if ($mainId) {
                DB::table('orders')->where('id', $mainId)->update([
                    'driver_order_role' => 'main',
                    'main_order_id' => $mainId,
                    'extra_claimed_count' => $extraIds->count(),
                    'bundle_window_expires_at' => now()->addMinutes(10),
                ]);
                DB::table('orders')->whereIn('id', $extraIds)->update([
                    'driver_order_role' => 'extra', 'main_order_id' => $mainId,
                ]);
                DB::table('orders')->whereIn('id', $activeIds->diff($extraIds)->reject(fn ($id) => $id === $mainId))
                    ->update(['driver_order_role' => 'manual']);
            } else {
                DB::table('orders')->whereIn('id', $activeIds)->update(['driver_order_role' => 'extra']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_driver_role_status_index');
            $table->dropConstrainedForeignId('main_order_id');
            $table->dropColumn(['driver_order_role', 'extra_claimed_count', 'bundle_window_expires_at']);
        });
    }
};
