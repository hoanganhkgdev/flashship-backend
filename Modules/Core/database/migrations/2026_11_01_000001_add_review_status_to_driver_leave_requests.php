<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tài xế tự xin nghỉ trên app giờ phải chờ admin duyệt (pending →
        // approved/rejected). Chỉ phiếu `approved` mới chặn online, chặn nhận
        // đơn và miễn chấm ca. Phiếu cũ đều do admin ghi hoặc đã có hiệu lực
        // nên mặc định `approved` để không đổi lịch nghỉ đang chạy.
        Schema::table('driver_leave_requests', function (Blueprint $table) {
            $table->string('status', 20)->default('approved')->after('note');
            $table->foreignId('reviewed_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('reject_reason')->nullable()->after('reviewed_at');
            $table->index(['status', 'leave_date']);
        });
    }

    public function down(): void
    {
        Schema::table('driver_leave_requests', function (Blueprint $table) {
            $table->dropIndex(['status', 'leave_date']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'reviewed_at', 'reject_reason']);
        });
    }
};
