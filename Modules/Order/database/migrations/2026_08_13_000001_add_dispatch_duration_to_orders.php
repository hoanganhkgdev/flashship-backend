<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thêm metric theo dõi tốc độ dispatch — cho phép admin xem:
 *   - Trung bình mất bao lâu từ đặt đơn → có tài xế nhận
 *   - Bao nhiêu % đơn tìm được tài xế
 *   - Phân bố theo thành phố / giờ / loại dịch vụ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Thời điểm tài xế đầu tiên nhận đơn (acceptOrder / assignDriverDirectly)
            $table->timestamp('dispatch_found_at')->nullable()->after('dispatch_started_at');

            // Số giây từ dispatch_started_at → dispatch_found_at
            // null = chưa tìm được / không theo dõi được
            $table->unsignedSmallInteger('dispatch_duration_secs')->nullable()->after('dispatch_found_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['dispatch_found_at', 'dispatch_duration_secs']);
        });
    }
};
