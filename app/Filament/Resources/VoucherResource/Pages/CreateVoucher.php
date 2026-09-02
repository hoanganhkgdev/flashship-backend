<?php

namespace App\Filament\Resources\VoucherResource\Pages;

use App\Filament\Resources\VoucherResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVoucher extends CreateRecord
{
    protected static string $resource = VoucherResource::class;

    public function getTitle(): string
    {
        return 'Tạo mã giảm giá';
    }

    public function getSubheading(): ?string
    {
        return 'Thiết lập giá trị ưu đãi, phạm vi và giới hạn sử dụng.';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['city_id'] = Filament::getTenant()?->getKey();

        if ($data['type'] === 'freeship') {
            $data['value'] = 0;
        }

        return $data;
    }

    /**
     * Bỏ qua cơ chế tự gán tenant mặc định của Filament (nó tìm quan hệ
     * City::vouchers() không tồn tại → lỗi 500 "does not have a relationship
     * named [vouchers]"). city_id đã được gán trực tiếp từ tenant trong
     * mutateFormDataBeforeCreate().
     */
    protected function associateRecordWithTenant(Model $record, Model $tenant): Model
    {
        $record->save();

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
