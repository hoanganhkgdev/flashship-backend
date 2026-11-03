<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trạng thái lệnh chi PayOS của một yêu cầu rút tiền đang chờ:
 *   sending    — đang gọi PayOS (process chết giữa chừng thì vẫn thấy dấu vết)
 *   unknown    — không nhận được phản hồi: PayOS có thể đã chuyển, KHÔNG được từ chối/chuyển tay cho tới khi kiểm tra
 *   processing — PayOS đã nhận lệnh, ngân hàng chưa báo xong
 *   failed     — PayOS báo không thực hiện: được thử lại (mã tham chiếu mới) hoặc xử lý cách khác
 * payout_id là mã lệnh phía PayOS. Nullable: yêu cầu cũ và chuyển tay không có.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('withdraw_requests', 'payout_state')) {
            Schema::table('withdraw_requests', function (Blueprint $table) {
                $table->string('payout_state', 20)->nullable()->after('payout_method');
                $table->string('payout_id', 64)->nullable()->after('payout_state');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('withdraw_requests', 'payout_state')) {
            Schema::table('withdraw_requests', fn (Blueprint $table) => $table->dropColumn(['payout_state', 'payout_id']));
        }
    }
};
