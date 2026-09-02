<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\ShopResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditShop extends EditRecord
{
    protected static string $resource = ShopResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Cập nhật thông tin liên hệ, khu vực và trạng thái tài khoản.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()->label('Xem hồ sơ')->icon('heroicon-o-eye'),
            Actions\DeleteAction::make()->label('Xoá cửa hàng'),
        ];
    }
}
