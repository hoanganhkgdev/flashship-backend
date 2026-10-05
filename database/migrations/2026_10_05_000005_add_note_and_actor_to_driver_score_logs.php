<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thao tác thủ công của admin (đặt lại điểm...) phải để lại ai làm và vì sao.
        Schema::table('driver_score_logs', function (Blueprint $table) {
            $table->text('note')->nullable()->after('reason');
            $table->unsignedBigInteger('performed_by')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('driver_score_logs', function (Blueprint $table) {
            $table->dropColumn(['note', 'performed_by']);
        });
    }
};
