<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('driver_cccd_images', function (Blueprint $table) {
            $table->string('rejection_reason')->nullable()->after('status');
        });
        Schema::table('driver_licenses', function (Blueprint $table) {
            $table->string('rejection_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('driver_cccd_images', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
        Schema::table('driver_licenses', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
