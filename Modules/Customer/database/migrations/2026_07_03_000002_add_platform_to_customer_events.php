<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_events', function (Blueprint $table) {
            $table->string('platform', 20)->default('customer')->after('user_id');
        });

        // Gán lại platform cho dữ liệu cũ
        DB::table('customer_events')->update(['platform' => 'customer']);
    }

    public function down(): void
    {
        Schema::table('customer_events', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
