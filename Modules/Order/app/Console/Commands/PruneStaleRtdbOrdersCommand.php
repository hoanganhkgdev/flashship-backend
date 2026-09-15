<?php

namespace Modules\Order\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\RTDBService;
use Modules\Order\Models\Order;

class PruneStaleRtdbOrdersCommand extends Command
{
    protected $signature = 'orders:prune-stale-rtdb';

    protected $description = 'Xóa node Firebase orders không còn assigned hoặc processing trong MySQL';

    public function handle(): int
    {
        $nodes = RTDBService::getOrderStates();
        if ($nodes === null) {
            $this->warn('Không đọc được Firebase; bỏ qua để không xóa nhầm.');

            return self::FAILURE;
        }

        if ($nodes === []) {
            return self::SUCCESS;
        }

        $codes = array_map('strval', array_keys($nodes));
        $activeCodes = Order::whereIn('code', $codes)
            ->whereIn('status', ['assigned', 'processing'])
            ->pluck('code')
            ->map(fn ($code) => (string) $code)
            ->all();
        $staleCodes = array_values(array_diff($codes, $activeCodes));

        if (! RTDBService::clearOrders($staleCodes)) {
            $this->error('Không xóa được node orders cũ khỏi Firebase.');

            return self::FAILURE;
        }

        if ($staleCodes !== []) {
            Log::info('[RTDB] Đã dọn node orders cũ.', [
                'count' => count($staleCodes),
                'codes' => $staleCodes,
            ]);
        }
        $this->info('Đã dọn '.count($staleCodes).' node; giữ '.count($activeCodes).' node đang hoạt động.');

        return self::SUCCESS;
    }
}
