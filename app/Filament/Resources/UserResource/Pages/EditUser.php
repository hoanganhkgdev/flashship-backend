<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Cập nhật thông tin liên hệ và trạng thái tài khoản khách hàng.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()->label('Xem hồ sơ')->icon('heroicon-o-eye'),
            Actions\DeleteAction::make()->label('Xoá khách hàng'),
        ];
    }
}
