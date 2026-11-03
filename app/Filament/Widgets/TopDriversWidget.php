<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DriverResource;
use App\Filament\Support\TenantCity;
use App\Support\OrderMoney;
use Modules\Core\Services\OperationalSettings;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;

/** Tài xế chạy nhiều đơn nhất hôm nay. */
class TopDriversWidget extends Widget
{
    use TenantCity;

    public static function canView(): bool
    {
        return ! auth()->user()?->isCallCenter();
    }

    protected static string $view = 'filament.widgets.top-drivers';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = ['default' => 12, 'md' => 12, 'xl' => 4];

    protected static ?int $sort = 6;

    public function getDrivers(): Collection
    {
        $cityId = $this->cityId();
        // Thu nhập = khách trả + công ty chi, cùng công thức với trang Thu nhập tài xế.
        $earnings = OrderMoney::driverEarnings(OperationalSettings::rainBonusAmount($cityId ?: null));
        $rows = Order::query()
            ->where('city_id', $cityId)
            ->where('status', 'completed')
            ->whereNotNull('delivery_man_id')
            ->where('completed_at', '>=', now()->startOfDay())->where('completed_at', '<', now()->startOfDay()->addDay())
            ->selectRaw('delivery_man_id, COUNT(*) AS c, COALESCE(SUM('.$earnings.'), 0) AS fee')
            ->groupBy('delivery_man_id')
            ->orderByDesc('c')
            ->orderByDesc('fee')
            ->limit(5)
            ->get();

        $names = User::whereIn('id', $rows->pluck('delivery_man_id'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'id' => $r->delivery_man_id,
            'name' => $names[$r->delivery_man_id] ?? 'Tài xế #'.$r->delivery_man_id,
            'orders' => (int) $r->c,
            'fee' => number_format((float) $r->fee, 0, ',', '.').'₫',
            'url' => DriverResource::getUrl('view', ['record' => $r->delivery_man_id]),
        ]);
    }
}
