<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zalo_tokens', function (Blueprint $table) {
            $table->text('last_error')->nullable()->after('expires_at');
            $table->timestamp('last_error_at')->nullable()->after('last_error');
            $table->timestamp('last_refreshed_at')->nullable()->after('last_error_at');
        });
    }

    public function down(): void
    {
        Schema::table('zalo_tokens', function (Blueprint $table) {
            $table->dropColumn(['last_error', 'last_error_at', 'last_refreshed_at']);
        });
    }
};
