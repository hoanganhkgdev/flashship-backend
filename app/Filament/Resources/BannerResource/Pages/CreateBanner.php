<?php

namespace App\Filament\Resources\BannerResource\Pages;

use App\Filament\Resources\BannerResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBanner extends CreateRecord
{
    protected static string $resource = BannerResource::class;

    public function getTitle(): string
    {
        return 'Thêm banner';
    }

    public function getSubheading(): ?string
    {
        return 'Tải hình ảnh và thiết lập phạm vi hiển thị trên ứng dụng.';
    }

    /** Ô "Khu vực hiển thị" là lựa chọn đơn giản; lưu thành city_id (null = mọi khu vực). */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['city_id'] = ($data['scope'] ?? 'city') === 'all' ? null : Filament::getTenant()?->id;
        unset($data['scope']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Bỏ qua cơ chế tự gán tenant mặc định của Filament (City::banners()
     * không tồn tại và không nên thêm — city_id có thể null nghĩa là banner
     * áp dụng toàn hệ thống, form tự chọn, không nên bị ép theo tenant đang
     * đứng).
     */
    protected function associateRecordWithTenant(Model $record, Model $tenant): Model
    {
        $record->save();

        return $record;
    }
}
