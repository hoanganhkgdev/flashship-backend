<?php

namespace App\Filament\Resources\BannerResource\Pages;

use App\Filament\Resources\BannerResource;
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
        return 'Quản lý hình ảnh truyền thông, liên kết và thứ tự hiển thị theo khu vực.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Thêm banner')->icon('heroicon-o-plus'),
        ];
    }
}
