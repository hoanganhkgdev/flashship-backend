<?php

namespace App\Filament\Resources\DriverRatingResource\Pages;

use App\Filament\Resources\DriverRatingResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Pages\Concerns\ExposesTableToWidgets;

class ListDriverRatings extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = DriverRatingResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            DriverRatingResource\Widgets\RatingStatsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
