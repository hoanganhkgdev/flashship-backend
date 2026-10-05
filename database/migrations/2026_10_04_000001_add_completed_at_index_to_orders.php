<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dashboard đếm đơn/doanh thu theo ngày hoàn thành (kèm lọc khu vực).
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['city_id', 'completed_at'], 'orders_city_completed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_city_completed_at_index');
        });
    }
};
