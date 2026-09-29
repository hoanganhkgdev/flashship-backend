<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // NULL = chưa chốt mức (ví dụ đơn nhận bởi code cũ trong lúc deploy)
            // → lúc hoàn thành dùng mức đang cấu hình; 0 = cố ý không thưởng.
            $table->unsignedInteger('rain_bonus_amount')->nullable()->after('rain_bonus_eligible');
        });

        // Các đơn đã nhận lúc mưa trước migration vẫn giữ đúng mức cũ.
        DB::table('orders')->where('rain_bonus_eligible', true)->update(['rain_bonus_amount' => 5_000]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('rain_bonus_amount');
        });
    }
};
