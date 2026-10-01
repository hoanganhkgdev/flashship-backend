<?php

namespace App\Filament\Resources\DriverReferralResource\Pages;

use App\Filament\Resources\DriverReferralResource;
use Filament\Resources\Pages\ListRecords;

class ListDriverReferrals extends ListRecords
{
    protected static string $resource = DriverReferralResource::class;

    public function getHeading(): string
    {
        return 'Giới thiệu shop';
    }

    public function getSubheading(): ?string
    {
        return 'Theo dõi tài xế giới thiệu shop mới. Mức thưởng và số đơn tối thiểu chỉnh trong Cấu hình vận hành.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
