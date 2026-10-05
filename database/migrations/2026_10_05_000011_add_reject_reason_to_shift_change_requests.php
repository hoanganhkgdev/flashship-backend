<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_shift_change_requests', function (Blueprint $table) {
            $table->string('reject_reason', 40)->nullable()->after('admin_note');
        });
    }

    public function down(): void
    {
        Schema::table('driver_shift_change_requests', function (Blueprint $table) {
            $table->dropColumn('reject_reason');
        });
    }
};
