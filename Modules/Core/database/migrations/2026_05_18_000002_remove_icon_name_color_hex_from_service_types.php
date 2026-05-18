<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->dropColumn(['icon_name', 'color_hex']);
        });
    }

    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->string('icon_name')->default('category')->after('icon_url');
            $table->string('color_hex', 7)->default('#FF6B35')->after('icon_name');
        });
    }
};
