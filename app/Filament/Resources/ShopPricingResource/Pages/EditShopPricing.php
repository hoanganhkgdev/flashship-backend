<?php

namespace App\Filament\Resources\ShopPricingResource\Pages;

use App\Filament\Resources\ShopPricingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditShopPricing extends EditRecord
{
    protected static string $resource = ShopPricingResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa bảng giá cửa hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Thay đổi này ảnh hưởng đến các đơn cửa hàng được báo giá sau khi lưu.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Xoá bảng giá'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
