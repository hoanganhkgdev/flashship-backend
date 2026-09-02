<?php

namespace App\Filament\Resources\AdminUserResource\Pages;

use App\Filament\Resources\AdminUserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAdminUser extends EditRecord
{
    protected static string $resource = AdminUserResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Thay đổi vai trò có thể ảnh hưởng ngay đến phạm vi truy cập backend.';
    }

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->label('Xoá tài khoản')];
    }
}
