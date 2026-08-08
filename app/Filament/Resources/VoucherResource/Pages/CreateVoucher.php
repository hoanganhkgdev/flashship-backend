<?php

namespace App\Filament\Resources\VoucherResource\Pages;

use App\Filament\Resources\VoucherResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVoucher extends CreateRecord
{
    protected static string $resource = VoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if ($data['type'] === 'freeship') {
            $data['value'] = 0;
        }
        return $data;
    }

    /**
     * Bỏ qua cơ chế tự gán tenant mặc định của Filament (nó tìm quan hệ
     * City::vouchers() không tồn tại → lỗi 500 "does not have a relationship
     * named [vouchers]"). city_id của voucher chọn TRỰC TIẾP trong form
     * (dropdown đủ mọi thành phố, để trống = áp dụng mọi khu vực) — không
     * nên ép ghi đè theo tenant đang đứng, sẽ phá mất lựa chọn "Tất cả khu
     * vực" hoặc chọn nhầm sang thành phố khác city_id thật đã chọn.
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
