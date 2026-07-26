<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Chụp lại lúc gán tài xế (accept/gán tay) xem thành phố có đang bật
            // chế độ trời mưa không — khoá luôn giá trị này cho đơn, không đổi
            // theo trạng thái mưa hiện tại nữa dù tắt/bật lại giữa chừng.
            $table->boolean('rain_bonus_eligible')->default(false)->after('is_freeship');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('rain_bonus_eligible');
        });
    }
};
