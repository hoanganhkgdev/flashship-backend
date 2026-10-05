<?php

namespace App\Console\Commands;

use App\Services\BankCodeService;
use Illuminate\Console\Command;

class FixLegacyBankCodes extends Command
{
    protected $signature = 'banks:fix-legacy-codes {--apply : Ghi vào CSDL (mặc định chỉ chạy thử)}';

    protected $description = 'Quy đổi mã ngân hàng cũ dạng chữ (VIETCOMBANK, MBBANK...) của tài xế về mã BIN 6 số';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $r = BankCodeService::fixLegacy($apply);

        $this->info(($apply ? 'ĐÃ SỬA ' : 'CHẠY THỬ (thêm --apply để ghi) — sẽ sửa ').$r['fixed']->count().' tài khoản:');
        foreach ($r['fixed'] as $f) {
            $this->line("  #{$f['account']->user_id} {$f['account']->driver}: {$f['account']->bank_code} → {$f['bank']->code} ({$f['bank']->name})");
        }

        if ($r['unmatched']->isNotEmpty()) {
            $this->warn($r['unmatched']->count().' tài khoản không khớp được, tài xế cần tự cập nhật trong app:');
            foreach ($r['unmatched'] as $u) {
                $this->line("  #{$u->user_id} {$u->driver}: mã '{$u->bank_code}', tên '{$u->bank_name}'");
            }
        }

        return self::SUCCESS;
    }
}
