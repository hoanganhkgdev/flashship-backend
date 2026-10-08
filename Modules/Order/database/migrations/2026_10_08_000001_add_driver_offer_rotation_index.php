<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_dispatch_logs', function (Blueprint $table) {
            $table->index(['driver_id', 'offered_at'], 'dispatch_logs_driver_offered_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_dispatch_logs', function (Blueprint $table) {
            $table->dropIndex('dispatch_logs_driver_offered_index');
        });
    }
};
