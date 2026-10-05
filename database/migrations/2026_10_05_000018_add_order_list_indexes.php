<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Danh sách đơn: lọc khu vực + sắp theo thời gian tạo, và đếm theo trạng thái.
            $table->index(['city_id', 'created_at'], 'orders_city_created_at_index');
            $table->index(['city_id', 'status', 'created_at'], 'orders_city_status_created_at_index');
            // Tìm theo SĐT giao (SĐT lấy đã có chỉ mục).
            $table->index('delivery_phone', 'orders_delivery_phone_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_city_created_at_index');
            $table->dropIndex('orders_city_status_created_at_index');
            $table->dropIndex('orders_delivery_phone_index');
        });
    }
};
