<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['daily_penalty_points', 'daily_penalty_date']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('daily_penalty_points')->default(0)->after('daily_bonus_date');
            $table->date('daily_penalty_date')->nullable()->after('daily_penalty_points');
        });
    }
};
