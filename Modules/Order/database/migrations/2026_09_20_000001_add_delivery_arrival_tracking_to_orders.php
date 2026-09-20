<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('delivery_arrived_at')->nullable()->after('delivery_reminder_sent_at');
            $table->timestamp('auto_completed_at')->nullable()->after('delivery_arrived_at');
            $table->index(['status', 'delivery_arrived_at'], 'orders_status_delivery_arrived_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_delivery_arrived_index');
            $table->dropColumn(['delivery_arrived_at', 'auto_completed_at']);
        });
    }
};
