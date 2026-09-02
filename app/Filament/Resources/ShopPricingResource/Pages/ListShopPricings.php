<?php

namespace App\Filament\Resources\ShopPricingResource\Pages;

use App\Filament\Resources\ShopPricingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListShopPricings extends ListRecords
{
    protected static string $resource = ShopPricingResource::class;

    public function getHeading(): string
    {
        return 'Bảng giá cửa hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Cấu hình phí giao theo loại hàng, quãng đường và trọng lượng.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Thêm bảng giá')->icon('heroicon-o-plus'),
        ];
    }
}
