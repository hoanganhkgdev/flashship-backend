<?php

namespace App\Filament\Resources\DriverDebtResource\Pages;

use App\Filament\Resources\DriverDebtResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDriverDebt extends CreateRecord
{
    protected static string $resource = DriverDebtResource::class;

    public function getTitle(): string
    {
        return 'Tạo công nợ';
    }

    public function getSubheading(): ?string
    {
        return 'Ghi nhận khoản phải thu theo tài xế và kỳ đối soát.';
    }

    /**
     * Bỏ qua cơ chế tự gán tenant mặc định của Filament — DriverDebt không
     * có cột city_id trực tiếp (khu vực xác định gián tiếp qua driver_id ->
     * users.city_id), City::driverDebts() không tồn tại và không nên tồn tại.
     */
    protected function associateRecordWithTenant(Model $record, Model $tenant): Model
    {
        $record->save();

        return $record;
    }
}
