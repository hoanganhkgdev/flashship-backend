<?php

namespace App\Filament\Resources\DriverRatingResource\Pages;

use App\Filament\Resources\DriverRatingResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Pages\Concerns\ExposesTableToWidgets;

class ListDriverRatings extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = DriverRatingResource::class;

    public function getHeading(): string { return ''; }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
