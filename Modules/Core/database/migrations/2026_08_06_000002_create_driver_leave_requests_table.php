<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin tạo trực tiếp = đã duyệt luôn (tài xế báo nghỉ qua điện thoại/
        // Zalo, chưa có kênh tự xin nghỉ trong app) — không cần cột status
        // riêng. Có bản ghi cho đúng driver_id + leave_date là đủ để
        // ScoreShiftSessionsCommand miễn chấm "Có mặt" cho ca hôm đó.
        Schema::create('driver_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->date('leave_date');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['driver_id', 'leave_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_leave_requests');
    }
};
