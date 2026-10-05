<?php

namespace App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Modules\Core\Models\ServiceType;
use Modules\Order\Models\Order;

/** Các đơn đang chạy ngoài đường (đã có tài xế, chưa giao xong). */
class LiveOrdersWidget extends Widget
{
    private const LIMIT = 8;

    private const LIVE_STATUSES = ['assigned', 'processing'];

    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static string $view = 'filament.widgets.live-orders';

    protected static ?string $pollingInterval = '15s';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = ['default' => 12, 'xl' => 7];

    protected static ?int $sort = 3;

    private function query()
    {
        return Order::query()
            ->when(Filament::getTenant()?->id, fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->whereIn('status', self::LIVE_STATUSES);
    }

    public function getOrders(): Collection
    {
        return $this->query()
            ->with('driver:id,name,phone')
            ->orderByDesc('updated_at')
            ->limit(self::LIMIT)
            ->get(['id', 'code', 'service_type', 'status', 'delivery_man_id', 'pickup_address', 'delivery_address', 'shipping_fee', 'created_at', 'updated_at']);
    }

    public function getTotal(): int
    {
        return $this->query()->count();
    }

    public function serviceLabel(?string $key): string
    {
        static $labels = null;
        $labels ??= ServiceType::pluck('label', 'key');

        return $labels[$key] ?? ($key ?: '—');
    }

    public function getLimit(): int
    {
        return self::LIMIT;
    }
}
