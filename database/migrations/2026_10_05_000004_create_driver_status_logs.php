<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lịch sử đổi trạng thái tài khoản tài xế: duyệt, khóa, mở khóa — ai làm, khi nào, vì sao.
        Schema::create('driver_status_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_id')->index();
            $table->string('action', 12);                       // approved | locked | unlocked
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_status_logs');
    }
};
