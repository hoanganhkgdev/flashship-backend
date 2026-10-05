<?php

namespace App\Filament\Resources\BannerResource\Pages;

use App\Filament\Resources\BannerResource;
use App\Filament\Resources\BannerResource\Widgets\BannerStatsWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBanners extends ListRecords
{
    protected static string $resource = BannerResource::class;

    public function getHeading(): string
    {
        return 'Banner ứng dụng';
    }

    public function getSubheading(): ?string
    {
        return 'Hình ảnh trên trang chủ app khách hàng: thứ tự, khu vực và thời gian hiển thị.';
    }

    protected function getHeaderWidgets(): array
    {
        return [BannerStatsWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Thêm banner')->icon('heroicon-o-plus'),
        ];
    }
}
