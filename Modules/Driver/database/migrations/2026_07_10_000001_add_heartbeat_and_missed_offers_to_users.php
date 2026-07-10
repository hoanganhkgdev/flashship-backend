<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nhịp tim app (30s/lần kể cả đứng yên) — đồng bộ từ RTDB heartbeat_at.
            // Khác last_location_at (chỉ đổi khi di chuyển ≥10m): heartbeat chết
            // nghĩa là app chết thật, dùng để loại khỏi phát đơn.
            $table->timestamp('last_heartbeat_at')->nullable()->after('last_location_at');

            // Số offer bỏ lỡ liên tiếp (không xem) — đủ 3 lần tự tắt online.
            $table->unsignedTinyInteger('consecutive_missed_offers')->default(0)->after('last_heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_heartbeat_at', 'consecutive_missed_offers']);
        });
    }
};
