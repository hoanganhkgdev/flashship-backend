<?php

namespace App\Filament\Resources\ShopVoucherResource\Pages;

use App\Filament\Resources\ShopVoucherResource;
use App\Filament\Resources\VoucherResource\Pages\ListVouchers;

class ListShopVouchers extends ListVouchers
{
    protected static string $resource = ShopVoucherResource::class;

    public function getHeading(): string
    {
        return 'Mã giảm giá cửa hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Ưu đãi dành riêng cho cửa hàng theo khu vực.';
    }
}
