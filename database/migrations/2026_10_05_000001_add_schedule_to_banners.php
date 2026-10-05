<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hẹn giờ hiển thị banner: null = không giới hạn đầu/cuối.
        Schema::table('banners', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable()->after('sort_order');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};
