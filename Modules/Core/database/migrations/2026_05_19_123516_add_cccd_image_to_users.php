<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'cccd_image_path')) {
                $table->string('cccd_image_path')->nullable()->after('cccd');
                $table->string('cccd_image_status')->nullable()->after('cccd_image_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['cccd_image_path', 'cccd_image_status']);
        });
    }
};
