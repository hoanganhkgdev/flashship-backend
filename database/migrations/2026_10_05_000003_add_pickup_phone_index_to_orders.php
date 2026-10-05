<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trang Cửa hàng khớp đơn tổng đài với shop theo số điện thoại lấy hàng.
        Schema::table('orders', function (Blueprint $table) {
            $table->index('pickup_phone', 'orders_pickup_phone_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_pickup_phone_index');
        });
    }
};
