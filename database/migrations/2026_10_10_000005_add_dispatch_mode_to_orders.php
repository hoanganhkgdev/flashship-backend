<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Nullable giúp rolling deploy: đơn cũ và đơn do phiên bản backend
            // cũ tạo trong lúc deploy được hiểu là system_assigned.
            $table->string('dispatch_mode', 32)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('dispatch_mode');
        });
    }
};
