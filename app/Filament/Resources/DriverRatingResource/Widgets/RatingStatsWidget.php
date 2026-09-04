<?php

namespace App\Filament\Resources\DriverRatingResource\Widgets;

use App\Filament\Resources\DriverRatingResource;
use Filament\Widgets\Widget;

class RatingStatsWidget extends Widget
{
    protected static string $view = 'filament.widgets.rating-stats';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $base = DriverRatingResource::getEloquentQuery()->reorder();

        $total = (clone $base)->count();
        $average = $total > 0 ? round((clone $base)->avg('driver_rating'), 1) : 0;
        $low = (clone $base)->where('driver_rating', '<=', 2)->count();

        $distribution = [];
        foreach ([5, 4, 3, 2, 1] as $star) {
            $distribution[$star] = (clone $base)->where('driver_rating', $star)->count();
        }

        $averageColor = $average >= 4.5 ? '#22c55e' : ($average >= 3.5 ? '#f59e0b' : '#ef4444');

        return compact('total', 'average', 'low', 'distribution', 'averageColor');
    }
}
