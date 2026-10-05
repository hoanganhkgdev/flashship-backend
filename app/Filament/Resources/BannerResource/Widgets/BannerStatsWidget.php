<?php

namespace App\Filament\Resources\BannerResource\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Modules\Admin\Models\Banner;

/** Dải chỉ số đầu trang Banner. Nằm ngoài app/Filament/Widgets để không bị tự thêm vào Tổng quan. */
class BannerStatsWidget extends Widget
{
    protected static string $view = 'filament.resources.banner-resource.widgets.banner-stats';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $cityId = Filament::getTenant()?->id;
        $banners = Banner::query()->where(fn ($q) => $q->whereNull('city_id')->orWhere('city_id', $cityId))->get();

        $count = fn (string $status) => $banners->filter(fn (Banner $b) => $b->statusKey() === $status)->count();
        $missing = $banners->filter(fn (Banner $b) => $b->imageMissing());

        return [
            'total' => $banners->count(),
            'live' => $count('live'), 'scheduled' => $count('scheduled'), 'expired' => $count('expired'), 'inactive' => $count('inactive'),
            'missing' => $missing->count(),
            'missingTitles' => $missing->pluck('title')->all(),
        ];
    }
}
