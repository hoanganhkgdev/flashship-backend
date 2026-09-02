<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\ShopResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShop extends CreateRecord
{
    protected static string $resource = ShopResource::class;

    public function getTitle(): string
    {
        return 'Thêm cửa hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Tạo tài khoản đối tác và thiết lập địa chỉ lấy hàng mặc định.';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_type'] = 'shop';
        $data['status'] = $data['status'] ?? 1;

        return $data;
    }
}
