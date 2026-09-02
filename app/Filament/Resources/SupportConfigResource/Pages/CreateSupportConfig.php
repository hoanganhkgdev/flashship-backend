<?php

namespace App\Filament\Resources\SupportConfigResource\Pages;

use App\Filament\Resources\SupportConfigResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSupportConfig extends CreateRecord
{
    protected static string $resource = SupportConfigResource::class;

    public function getTitle(): string
    {
        return 'Thêm kênh hỗ trợ';
    }

    public function getSubheading(): ?string
    {
        return 'Thiết lập thông tin liên hệ và khu vực hiển thị trên ứng dụng.';
    }

    /**
     * Bỏ qua cơ chế tự gán tenant mặc định của Filament (City::supportConfigs()
     * không tồn tại — city_id=null nghĩa là áp dụng mọi khu vực, form tự
     * chọn, không nên bị ép theo tenant đang đứng).
     */
    protected function associateRecordWithTenant(Model $record, Model $tenant): Model
    {
        $record->save();

        return $record;
    }
}
