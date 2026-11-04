<?php

namespace Modules\Order\Console\Commands;

use Illuminate\Console\Command;
use Modules\Order\Services\OrderMarketService;

class SyncMarketToFirebaseCommand extends Command
{
    protected $signature = 'market:sync-firebase {--city= : Chỉ đồng bộ một thành phố (id)}';

    protected $description = 'Đồng bộ danh sách đơn mới từ MySQL lên Firebase (nhánh order_market/city_X/orders)';

    public function handle(OrderMarketService $market): int
    {
        // Mọi thành phố (kể cả không còn đơn mở) để xoá đơn mồ côi trên Firebase.
        $cityIds = $this->option('city')
            ? collect([(int) $this->option('city')])
            : \Modules\Core\Models\City::pluck('id');

        $total = 0;
        foreach ($cityIds as $cityId) {
            // Lưới an toàn chạy 5 phút/lần: không ping revision, tránh đánh thức mọi máy khi không có gì đổi.
            if ($market->syncCity((int) $cityId, ping: false)) {
                $total += $market->openCount((int) $cityId);
            } else {
                $this->warn("Không ghi được Firebase cho thành phố {$cityId}.");
            }
        }

        $this->info("Đã đồng bộ {$total} đơn lên Firebase.");

        return self::SUCCESS;
    }
}
