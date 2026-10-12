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
            // Điểm giao chỉ là tâm khu vực shop chọn (không có địa chỉ thật): tài xế
            // được hoàn thành trong bán kính khu vực thay vì ngưỡng mặc định.
            $table->decimal('delivery_area_radius_km', 4, 1)->nullable()->after('delivery_lng');
        });

        $this->backfillOpenOrders();
    }

    /**
     * Đơn chưa xong được tạo trước migration này mà điểm giao đúng bằng tâm một
     * khu vực (cùng tên, cùng toạ độ) coi như đơn chỉ chọn khu vực.
     */
    private function backfillOpenOrders(): void
    {
        if (! Schema::hasTable('shop_destination_prices')) {
            return;
        }

        $zones = DB::table('shop_destination_prices')->get();
        if ($zones->isEmpty()) {
            return;
        }
        $isCentre = fn (object $zone, ?string $name, $lat, $lng) => $name === $zone->name
            && abs((float) $lat - (float) $zone->lat) < 0.00001
            && abs((float) $lng - (float) $zone->lng) < 0.00001;

        DB::table('orders')->whereIn('status', ['pending', 'assigned', 'processing'])
            ->whereNotNull('delivery_lat')->orderBy('id')->each(function ($order) use ($zones, $isCentre) {
                $zone = $zones->where('city_id', $order->city_id)->first(
                    fn ($z) => $isCentre($z, $order->delivery_address, $order->delivery_lat, $order->delivery_lng)
                );
                if ($zone && ! $order->is_batch) {
                    DB::table('orders')->where('id', $order->id)->update(['delivery_area_radius_km' => $zone->radius_km]);
                }

                if ($order->is_batch && $order->stops) {
                    $stops = json_decode($order->stops, true) ?: [];
                    $changed = false;
                    foreach ($stops as &$stop) {
                        $match = $zones->where('city_id', $order->city_id)->first(
                            fn ($z) => $isCentre($z, $stop['address'] ?? null, $stop['lat'] ?? null, $stop['lng'] ?? null)
                        );
                        if ($match && ! isset($stop['area_radius_km'])) {
                            $stop['area_radius_km'] = (float) $match->radius_km;
                            $changed = true;
                        }
                    }
                    unset($stop);
                    if ($changed) {
                        DB::table('orders')->where('id', $order->id)->update(['stops' => json_encode($stops, JSON_UNESCAPED_UNICODE)]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_area_radius_km');
        });
    }
};
