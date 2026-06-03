<?php

namespace App\Filament\Resources\ShopPricingResource\Pages;

use App\Filament\Resources\ShopPricingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListShopPricings extends ListRecords
{
    protected static string $resource = ShopPricingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Thêm bảng giá'),
        ];
    }
}
