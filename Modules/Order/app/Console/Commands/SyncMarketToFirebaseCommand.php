<?php

namespace Modules\Order\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Services\RTDBService;
use Modules\Order\Models\OrderMarketListing;
use Modules\Order\Services\OrderMarketService;

class SyncMarketToFirebaseCommand extends Command
{
    protected $signature = 'market:sync-firebase {--city= : Chỉ đồng bộ một thành phố (id)}';

    protected $description = 'Đồng bộ danh sách đơn mới từ MySQL lên Firebase (nhánh order_market/city_X/orders)';

    public function handle(OrderMarketService $market): int
    {
        $listings = OrderMarketListing::with('order')
            ->where('status', 'open')
            ->whereHas('order', fn ($orders) => $orders->where('status', 'pending'))
            ->get()
            ->groupBy('city_id');

        // Thành phố có listing đang mở + mọi thành phố khác để xoá đơn mồ côi trên Firebase.
        $cityIds = \Modules\Core\Models\City::pluck('id')->merge($listings->keys())->unique();
        if ($this->option('city')) {
            $cityIds = collect([(int) $this->option('city')]);
        }

        $total = 0;
        foreach ($cityIds as $cityId) {
            $payloads = [];
            foreach ($listings->get($cityId, collect()) as $listing) {
                $payloads[(string) $listing->order_id] = $market->payloadFor($listing->order, $listing);
            }
            if (RTDBService::replaceMarketOrders((int) $cityId, $payloads)) {
                $total += count($payloads);
            } else {
                $this->warn("Không ghi được Firebase cho thành phố {$cityId}.");
            }
        }

        $this->info("Đã đồng bộ {$total} đơn lên Firebase.");

        return self::SUCCESS;
    }
}
