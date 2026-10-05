<?php

namespace App\Filament\Resources\ServiceTypeResource\Widgets;

use App\Services\CatalogService;
use Filament\Widgets\Widget;
use Modules\Core\Models\City;
use Modules\Core\Models\ServiceType;

/** KPI + ma trận giá dịch vụ × khu vực đầu trang Dịch vụ. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class ServiceCoverageWidget extends Widget
{
    protected static string $view = 'filament.resources.service-type-resource.widgets.service-coverage';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $services = ServiceType::orderBy('sort_order')->get();
        $cities = City::public()->orderBy('name')->get();
        $matrix = CatalogService::pricingMatrix();
        $orders = CatalogService::serviceOrders();

        $gaps = 0;
        foreach ($services->where('is_active', true) as $s) {
            foreach ($cities as $c) {
                $gaps += ($matrix[$c->id][$s->key] ?? false) ? 0 : 1;
            }
        }
        $top = $services->sortByDesc(fn ($s) => $orders['by'][$s->key] ?? 0)->first();

        return [
            'services' => $services, 'cities' => $cities, 'matrix' => $matrix, 'gaps' => $gaps,
            'active' => $services->where('is_active', true)->count(), 'total' => $services->count(),
            'noIcon' => $services->filter(fn ($s) => ! $s->icon_url)->count(),
            'orders' => $orders, 'top' => $top,
        ];
    }
}
