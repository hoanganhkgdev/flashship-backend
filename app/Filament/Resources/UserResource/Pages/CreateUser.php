<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return 'Thêm khách hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Tạo tài khoản khách hàng mới trong khu vực.';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_type'] = 'customer';
        $data['status'] = $data['status'] ?? 1;

        return $data;
    }
}
