<?php

namespace App\Console\Commands;

use App\Services\ZaloTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ZaloRefreshToken extends Command
{
    protected $signature   = 'zalo:refresh-token {--force : Refresh ngay cả khi token còn hạn}';
    protected $description = 'Tự động refresh Zalo access token khi sắp hết hạn';

    public function handle(): int
    {
        $row = DB::table('zalo_tokens')->orderByDesc('id')->first();

        if (!$row) {
            $this->error('Chưa có token trong DB. Chạy app một lần để seed từ .env.');
            return self::FAILURE;
        }

        $minutesLeft = $row->expires_at ? now()->diffInMinutes($row->expires_at, false) : 0;

        if (!$this->option('force') && $minutesLeft > 30) {
            $this->info("Token còn {$minutesLeft} phút, chưa cần refresh.");
            return self::SUCCESS;
        }

        $this->info($this->option('force') ? 'Force refresh...' : "Token còn {$minutesLeft} phút, đang refresh...");

        if (ZaloTokenService::refresh($row)) {
            $newRow = DB::table('zalo_tokens')->orderByDesc('id')->first();
            $newMins = $newRow?->expires_at ? now()->diffInMinutes($newRow->expires_at, false) : '?';
            $this->info("Refresh thành công. Token mới có hiệu lực {$newMins} phút.");
            return self::SUCCESS;
        }

        $this->error('Refresh thất bại. Kiểm tra log để biết thêm chi tiết.');
        return self::FAILURE;
    }
}
