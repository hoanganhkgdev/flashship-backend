<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index(
                ['city_id', 'user_type', 'status', 'is_online', 'score_suspended_until'],
                'users_dispatch_candidate_index',
            );
        });
        Schema::table('driver_debts', function (Blueprint $table) {
            $table->index(['driver_id', 'status'], 'driver_debts_driver_status_index');
        });
        Schema::table('driver_licenses', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'driver_licenses_user_status_index');
        });
        Schema::table('order_dispatch_logs', function (Blueprint $table) {
            $table->index(['driver_id', 'result'], 'dispatch_logs_driver_result_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_dispatch_logs', function (Blueprint $table) {
            $table->dropIndex('dispatch_logs_driver_result_index');
        });
        Schema::table('driver_licenses', function (Blueprint $table) {
            $table->dropIndex('driver_licenses_user_status_index');
        });
        Schema::table('driver_debts', function (Blueprint $table) {
            $table->dropIndex('driver_debts_driver_status_index');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_dispatch_candidate_index');
        });
    }
};
