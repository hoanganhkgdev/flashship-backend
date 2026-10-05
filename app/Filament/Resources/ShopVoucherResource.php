<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopVoucherResource\Pages;

/**
 * Mã giảm giá dành cho cửa hàng. Dùng chung form/bảng với mã khách hàng (VoucherResource),
 * chỉ khác đối tượng, nhóm menu và trang hiển thị.
 */
class ShopVoucherResource extends VoucherResource
{
    protected static string $audience = 'shop';

    protected static ?string $navigationGroup = 'Cửa hàng';

    protected static ?string $navigationLabel = 'Mã giảm giá cửa hàng';

    protected static ?string $modelLabel = 'Mã giảm giá cửa hàng';

    protected static ?string $pluralModelLabel = 'Mã giảm giá cửa hàng';

    protected static ?string $slug = 'shop-vouchers';

    protected static ?int $navigationSort = 2;

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShopVouchers::route('/'),
            'create' => Pages\CreateShopVoucher::route('/create'),
            'view' => Pages\ViewShopVoucher::route('/{record}'),
            'edit' => Pages\EditShopVoucher::route('/{record}/edit'),
        ];
    }
}
