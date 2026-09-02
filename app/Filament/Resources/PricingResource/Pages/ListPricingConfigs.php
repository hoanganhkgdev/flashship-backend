<?php

namespace App\Filament\Resources\PricingResource\Pages;

use App\Filament\Resources\PricingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPricingConfigs extends ListRecords
{
    protected static string $resource = PricingResource::class;

    public function getHeading(): string
    {
        return 'Bảng giá dịch vụ';
    }

    public function getSubheading(): ?string
    {
        return 'Cấu hình đơn giá mặc định hoặc bảng giá riêng theo từng khu vực.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Thêm bảng giá')->icon('heroicon-o-plus')];
    }
}
