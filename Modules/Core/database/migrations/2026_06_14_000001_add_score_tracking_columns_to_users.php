<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Giới hạn +10 điểm/ngày
            $table->unsignedSmallInteger('daily_bonus_points')->default(0)->after('consecutive_completed');
            $table->date('daily_bonus_date')->nullable()->after('daily_bonus_points');

            // Theo dõi thời gian online trong ngày (giây)
            $table->unsignedInteger('daily_online_seconds')->default(0)->after('daily_bonus_date');
            $table->date('daily_online_date')->nullable()->after('daily_online_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'daily_bonus_points',
                'daily_bonus_date',
                'daily_online_seconds',
                'daily_online_date',
            ]);
        });
    }
};
