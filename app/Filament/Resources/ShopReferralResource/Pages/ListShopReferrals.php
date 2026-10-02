<?php

namespace App\Filament\Resources\ShopReferralResource\Pages;

use App\Filament\Resources\ShopReferralResource;
use Filament\Resources\Pages\ListRecords;

class ListShopReferrals extends ListRecords
{
    protected static string $resource = ShopReferralResource::class;

    public function getHeading(): string
    {
        return 'Shop giới thiệu shop';
    }

    public function getSubheading(): ?string
    {
        return 'Theo dõi shop giới thiệu shop mới. Số điểm, số đơn tối thiểu và voucher chào mừng chỉnh trong Cấu hình vận hành.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
