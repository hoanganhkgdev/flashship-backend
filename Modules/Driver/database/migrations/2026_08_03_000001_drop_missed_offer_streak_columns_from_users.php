<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['consecutive_missed_offers', 'missed_offer_offline_count', 'missed_offer_offline_date']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('consecutive_missed_offers')->default(0)->after('last_heartbeat_at');
            $table->unsignedTinyInteger('missed_offer_offline_count')->default(0)->after('consecutive_missed_offers');
            $table->date('missed_offer_offline_date')->nullable()->after('missed_offer_offline_count');
        });
    }
};
