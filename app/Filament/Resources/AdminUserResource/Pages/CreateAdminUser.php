<?php

namespace App\Filament\Resources\AdminUserResource\Pages;

use App\Filament\Resources\AdminUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdminUser extends CreateRecord
{
    protected static string $resource = AdminUserResource::class;

    public function getTitle(): string
    {
        return 'Thêm tài khoản quản trị';
    }

    public function getSubheading(): ?string
    {
        return 'Phân vai trò và phạm vi khu vực phù hợp với nhiệm vụ nhân sự.';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_type'] = $data['user_type'] ?? 'subadmin';

        return $data;
    }
}
