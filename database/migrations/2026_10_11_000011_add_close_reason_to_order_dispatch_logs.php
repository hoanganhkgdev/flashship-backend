<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_dispatch_logs', function (Blueprint $table) {
            $table->string('close_reason', 40)->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('order_dispatch_logs', function (Blueprint $table) {
            $table->dropColumn('close_reason');
        });
    }
};
