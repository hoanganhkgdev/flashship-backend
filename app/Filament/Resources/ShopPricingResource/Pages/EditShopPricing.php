<?php

namespace App\Filament\Resources\ShopPricingResource\Pages;

use App\Filament\Resources\ShopPricingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditShopPricing extends EditRecord
{
    protected static string $resource = ShopPricingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Xoá'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
