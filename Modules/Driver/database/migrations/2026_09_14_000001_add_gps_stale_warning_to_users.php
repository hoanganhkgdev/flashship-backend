<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('gps_stale_notified_at')->nullable()->after('last_heartbeat_at');
            $table->timestamp('gps_stale_evidence_at')->nullable()->after('gps_stale_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gps_stale_notified_at', 'gps_stale_evidence_at']);
        });
    }
};
