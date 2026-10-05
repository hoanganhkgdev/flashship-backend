<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('cancel_note')->nullable()->after('cancel_reason');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancel_note');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->string('fee_note', 255)->nullable()->after('shipping_fee'); // lý do khi phí khác phí hệ thống tính hoặc bằng 0
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cancel_note', 'cancelled_by', 'cancelled_at', 'fee_note']);
        });
    }
};
