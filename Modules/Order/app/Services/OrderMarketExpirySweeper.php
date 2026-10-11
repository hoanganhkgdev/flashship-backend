<?php

namespace Modules\Order\Services;

use Modules\Order\Models\OrderMarketListing;

class OrderMarketExpirySweeper
{
    public function __construct(private readonly OrderMarketService $market) {}

    public function sweep(int $limit = 200): int
    {
        $ids = OrderMarketListing::query()
            ->where('status', 'open')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $this->market->expire((int) $id);
        }

        return $ids->count();
    }
}
