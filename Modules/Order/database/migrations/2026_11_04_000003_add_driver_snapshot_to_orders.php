<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tên và số điện thoại tài xế, chỉ được ghi ngay trước khi tài xế bị xóa (delivery_man_id lúc đó bị đặt NULL).
 * Nhờ vậy đơn cũ, đánh giá và thu nhập vẫn biết ai đã giao. Đơn của tài xế đã xóa từ trước không khôi phục được.
 * Thêm cột ở cuối bảng, nullable, không mặc định: MySQL 8 thêm tức thì, không khóa bảng đơn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'driver_name')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('driver_name', 100)->nullable();
                $table->string('driver_phone', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'driver_name')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['driver_name', 'driver_phone']));
        }
    }
};
