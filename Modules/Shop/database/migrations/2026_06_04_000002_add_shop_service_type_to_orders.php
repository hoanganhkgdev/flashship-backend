<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // shop_delivery = giao đơn, shop_pickup = lấy hộ, shop_batch = đơn gộp
            $table->string('shop_service_type', 20)->nullable()->after('is_batch');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shop_service_type');
        });
    }
};
