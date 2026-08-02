<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_dispatch_logs', function (Blueprint $table) {
            // Theo dõi "đã xem" theo TỪNG dòng offer (khác với orders.offer_viewed_at
            // là 1 cột dùng chung cho cả đơn) — hạ tầng để sau này tính chính xác
            // % offer bị bỏ lỡ (không mở xem) / tổng offer trong ca của từng tài xế.
            $table->timestamp('viewed_at')->nullable()->after('offered_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_dispatch_logs', function (Blueprint $table) {
            $table->dropColumn('viewed_at');
        });
    }
};
