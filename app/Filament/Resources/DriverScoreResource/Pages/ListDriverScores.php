<?php

namespace App\Filament\Resources\DriverScoreResource\Pages;

use App\Filament\Resources\DriverScoreResource;
use Filament\Resources\Pages\ListRecords;

class ListDriverScores extends ListRecords
{
    protected static string $resource = DriverScoreResource::class;

    protected function getHeaderActions(): array { return []; }
}
