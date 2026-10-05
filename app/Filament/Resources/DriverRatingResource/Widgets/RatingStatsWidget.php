<?php

namespace App\Filament\Resources\DriverRatingResource\Widgets;

use App\Filament\Resources\DriverResource;
use App\Services\DriverRatingService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class RatingStatsWidget extends Widget
{
    protected static string $view = 'filament.resources.driver-rating-resource.widgets.rating-stats';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $cityId = Filament::getTenant()?->id;

        return [
            's' => DriverRatingService::summary($cityId, 30),
            'watch' => DriverRatingService::watchList($cityId, 90)->map(fn ($w) => $w + ['url' => DriverResource::getUrl('view', ['record' => $w['id']])]),
        ];
    }
}
