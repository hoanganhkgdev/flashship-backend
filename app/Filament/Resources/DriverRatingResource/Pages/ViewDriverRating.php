<?php

namespace App\Filament\Resources\DriverRatingResource\Pages;

use App\Filament\Resources\DriverRatingResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Actions;

class ViewDriverRating extends ViewRecord
{
    protected static string $resource = DriverRatingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
