<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->boolean('is_rain_mode')->default(false)->after('is_active');
            $table->timestamp('rain_mode_started_at')->nullable()->after('is_rain_mode');
            $table->unsignedBigInteger('rain_mode_by')->nullable()->after('rain_mode_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn(['is_rain_mode', 'rain_mode_started_at', 'rain_mode_by']);
        });
    }
};
