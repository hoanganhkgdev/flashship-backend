<?php

namespace App\Filament\Resources\PricingResource\Pages;

use App\Filament\Resources\PricingResource;
use Filament\Resources\Pages\ListRecords;

class ListPricingConfigs extends ListRecords
{
    protected static string $resource = PricingResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
