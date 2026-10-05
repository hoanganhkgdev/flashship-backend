<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_wallet_transactions', function (Blueprint $table) {
            // Ai là người điều chỉnh khi là thao tác của admin; null với giao dịch tự động hoặc dữ liệu cũ.
            $table->unsignedBigInteger('performed_by')->nullable()->after('reference');
            $table->index('created_at', 'driver_wallet_tx_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('driver_wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('driver_wallet_tx_created_at_index');
            $table->dropColumn('performed_by');
        });
    }
};
