<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('order_dispatch_logs', 'received_at')) return;

        Schema::table('order_dispatch_logs', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('offered_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_dispatch_logs', 'received_at')) {
            Schema::table('order_dispatch_logs', fn (Blueprint $table) => $table->dropColumn('received_at'));
        }
    }
};
