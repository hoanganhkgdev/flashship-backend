<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['daily_online_seconds', 'daily_online_date']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('daily_online_seconds')->default(0)->after('daily_bonus_date');
            $table->date('daily_online_date')->nullable()->after('daily_online_seconds');
        });
    }
};
