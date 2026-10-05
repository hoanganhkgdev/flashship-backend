<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdraw_requests', function (Blueprint $table) {
            $table->string('payout_method', 20)->nullable()->after('payout_reference'); // payos | manual
            $table->string('reject_reason', 40)->nullable()->after('payout_method');
            $table->text('last_payout_error')->nullable()->after('reject_reason');
            $table->timestamp('last_payout_attempt_at')->nullable()->after('last_payout_error');
        });
    }

    public function down(): void
    {
        Schema::table('withdraw_requests', function (Blueprint $table) {
            $table->dropColumn(['payout_method', 'reject_reason', 'last_payout_error', 'last_payout_attempt_at']);
        });
    }
};
