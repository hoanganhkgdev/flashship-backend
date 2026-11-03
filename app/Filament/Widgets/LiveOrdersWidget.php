<?php

namespace App\Filament\Widgets;

use App\Filament\Support\TenantCity;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Modules\Core\Models\ServiceType;
use Modules\Order\Models\Order;

/** Các đơn đang cần vận hành theo dõi, ưu tiên đơn chờ tài xế trước. */
class LiveOrdersWidget extends Widget
{
    use TenantCity;

    private const LIMIT = 10;

    private const LIVE_STATUSES = ['pending', 'assigned', 'processing'];

    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static string $view = 'filament.widgets.live-orders';

    protected static bool $isLazy = false;

    // Đứng ngay dưới Ưu tiên xử lý, cạnh Nguồn lực tài xế: hai khối vận hành cần nhìn cùng lúc.
    protected int|string|array $columnSpan = ['default' => 12, 'xl' => 8];

    protected static ?int $sort = 3;

    private function query()
    {
        return Order::query()
            ->where('city_id', $this->cityId())
            ->whereIn('status', self::LIVE_STATUSES);
    }

    public function getOrders(): Collection
    {
        return $this->query()
            ->with('driver:id,name,phone')
            ->orderByRaw("FIELD(status, 'pending', 'assigned', 'processing')")
            ->orderByRaw("CASE WHEN status = 'pending' THEN created_at END ASC")
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

    public function urgentMinutes(): int
    {
        return OpsAlertsWidget::PENDING_WARN_MINUTES;
    }

    public function getLimit(): int
    {
        return self::LIMIT;
    }
}
