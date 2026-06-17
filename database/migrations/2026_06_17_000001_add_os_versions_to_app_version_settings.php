<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_version_settings', function (Blueprint $table) {
            $table->string('android_min_version')->nullable()->after('min_version');
            $table->string('android_latest_version')->nullable()->after('latest_version');
            $table->string('ios_min_version')->nullable()->after('android_min_version');
            $table->string('ios_latest_version')->nullable()->after('android_latest_version');
        });
    }

    public function down(): void
    {
        Schema::table('app_version_settings', function (Blueprint $table) {
            $table->dropColumn(['android_min_version', 'android_latest_version', 'ios_min_version', 'ios_latest_version']);
        });
    }
};
