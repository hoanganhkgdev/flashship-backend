<?php

namespace App\Filament\Resources\SupportConfigResource\Pages;

use App\Filament\Resources\SupportConfigResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSupportConfig extends CreateRecord
{
    protected static string $resource = SupportConfigResource::class;

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
