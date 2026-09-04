<?php

namespace App\Filament\Resources\DriverLeaveRequestResource\Pages;

use App\Filament\Resources\DriverLeaveRequestResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;
use Modules\Core\Services\RTDBService;
use Modules\Driver\Models\DriverGpsEligibleSession;
use Modules\Driver\Models\DriverShiftSession;
use Modules\Driver\Services\DriverLocationService;
use Modules\Order\Models\Order;

class CreateDriverLeaveRequest extends CreateRecord
{
    protected static string $resource = DriverLeaveRequestResource::class;

    public function getTitle(): string
    {
        return 'Ghi nhận nghỉ phép';
    }

    public function getSubheading(): ?string
    {
        return 'Chỉ ghi nhận khi tài xế đã báo nghỉ và không còn đơn đang thực hiện.';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (DriverLeaveRequest::where('driver_id', $data['driver_id'])
            ->whereDate('leave_date', $data['leave_date'])
            ->exists()) {
            throw ValidationException::withMessages(['data.leave_date' => 'Tài xế đã được ghi nhận nghỉ trong ngày này.']);
        }
        if (Order::where('delivery_man_id', $data['driver_id'])
            ->whereIn('status', ['assigned', 'processing'])->exists()) {
            throw ValidationException::withMessages(['data.driver_id' => 'Tài xế đang có đơn chưa hoàn thành, không thể ghi nghỉ phép.']);
        }
        $data['created_by'] = Auth::id();

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! $this->record->leave_date->isToday()) {
            return;
        }
        DB::transaction(function () {
            User::whereKey($this->record->driver_id)->update(['is_online' => false, 'online_since' => null]);
            DriverShiftSession::where('driver_id', $this->record->driver_id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);
            $gpsSessions = DriverGpsEligibleSession::where('driver_id', $this->record->driver_id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->get();
            foreach ($gpsSessions as $gpsSession) {
                $endedAt = $gpsSession->last_gps_at->copy()
                    ->addSeconds(DriverLocationService::POS_MAX_AGE_SECS)
                    ->min(now());
                $gpsSession->update(['ended_at' => $endedAt]);
            }
        });
        RTDBService::removeDriverLocation($this->record->driver_id);
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
