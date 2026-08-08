<?php

namespace App\Filament\Resources\DriverLeaveRequestResource\Pages;

use App\Filament\Resources\DriverLeaveRequestResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateDriverLeaveRequest extends CreateRecord
{
    protected static string $resource = DriverLeaveRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();

        return $data;
    }

    /**
     * Bỏ qua cơ chế tự gán tenant mặc định của Filament — DriverLeaveRequest
     * không có cột city_id trực tiếp (khu vực xác định gián tiếp qua
     * driver_id -> users.city_id, xem scopeEloquentQueryToTenant() trong
     * Resource), nên City::driverLeaveRequests() không tồn tại và không nên
     * tồn tại. Gây lỗi 500 "does not have a relationship named
     * [driverLeaveRequests]" nếu không override.
     */
    protected function associateRecordWithTenant(Model $record, Model $tenant): Model
    {
        $record->save();
        return $record;
    }
}
