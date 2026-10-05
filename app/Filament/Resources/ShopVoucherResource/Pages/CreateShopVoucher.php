<?php

namespace App\Filament\Resources\ShopVoucherResource\Pages;

use App\Filament\Resources\ShopVoucherResource;
use App\Filament\Resources\VoucherResource\Pages\CreateVoucher;

class CreateShopVoucher extends CreateVoucher
{
    protected static string $resource = ShopVoucherResource::class;

    public function getTitle(): string
    {
        return 'Tạo mã giảm giá cửa hàng';
    }
}
