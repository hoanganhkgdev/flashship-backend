<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Đếm dồn liên tục số offer bị bỏ lỡ KHÔNG xem — cứ đủ 3 thì trừ 1
            // điểm rồi reset về 0 (xem DriverScoreService::onOfferUnviewed()).
            // Thay hẳn cho luật % bỏ lỡ tính cuối ca cũ.
            $table->unsignedTinyInteger('unviewed_offer_count')->default(0)->after('driver_score');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('unviewed_offer_count');
        });
    }
};
