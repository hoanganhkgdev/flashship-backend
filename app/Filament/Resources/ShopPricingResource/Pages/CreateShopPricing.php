<?php

namespace App\Filament\Resources\ShopPricingResource\Pages;

use App\Filament\Resources\ShopPricingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShopPricing extends CreateRecord
{
    protected static string $resource = ShopPricingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
