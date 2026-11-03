<?php

namespace App\Console\Commands;

use App\Services\WithdrawService;
use Illuminate\Console\Command;
use Modules\Driver\Models\WithdrawRequest;

/**
 * Hỏi PayOS kết quả các lệnh chi còn dở (đang gửi, chưa rõ, đang xử lý). Lệnh xong thì duyệt và báo tài xế; PayOS
 * không có lệnh thì mở khóa để admin thử lại hoặc xử lý cách khác.
 */
class SyncPayosPayouts extends Command
{
    protected $signature = 'withdraw:sync-payos';

    protected $description = 'Cập nhật kết quả lệnh chi PayOS của các yêu cầu rút tiền còn dở';

    public function handle(): int
    {
        if (! WithdrawService::payosConfigured()) {
            return self::SUCCESS;
        }

        $done = 0;
        WithdrawRequest::where('status', 'pending')->whereIn('payout_state', WithdrawService::PAYOS_IN_FLIGHT)
            // Lệnh vừa gửi xong để phiên duyệt tự cập nhật, không giành khóa với nó.
            ->where('last_payout_attempt_at', '<', now()->subMinute())
            ->orderBy('id')->limit(50)->get()
            ->each(function (WithdrawRequest $r) use (&$done) {
                $res = WithdrawService::syncPayos($r);
                $this->line("#{$r->id}: {$res['message']}");
                $done++;
            });

        $this->info("Đã kiểm tra {$done} lệnh PayOS.");

        return self::SUCCESS;
    }
}
