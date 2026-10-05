<?php

namespace App\Filament\Resources\CityResource\Widgets;

use App\Services\CatalogService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/** KPI đầu trang Khu vực. Nằm ngoài app/Filament/Widgets để không lọt vào Tổng quan. */
class CityOverviewWidget extends Widget
{
    protected static string $view = 'filament.resources.city-resource.widgets.city-overview';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $health = CatalogService::health();
        $attention = $health->filter(fn ($h) => collect($h['flags'])->contains(fn ($f) => in_array($f['level'], ['warning', 'danger'], true)))->count();

        return [
            'active' => $health->filter(fn ($h) => $h['city']->is_active && ! $h['city']->is_test)->count(),
            'total' => $health->count(),
            'orders30' => $health->sum('orders30'),
            'attention' => $attention,
            'orphans' => DB::table('orders')->whereNull('city_id')->count(),
        ];
    }
}
