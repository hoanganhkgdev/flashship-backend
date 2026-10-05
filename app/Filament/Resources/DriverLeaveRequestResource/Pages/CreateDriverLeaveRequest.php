<?php

namespace App\Filament\Resources\DriverLeaveRequestResource\Pages;

use App\Filament\Resources\DriverLeaveRequestResource;
use App\Services\DriverLeaveService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDriverLeaveRequest extends CreateRecord
{
    protected static string $resource = DriverLeaveRequestResource::class;

    public function getTitle(): string
    {
        return 'Ghi nhận nghỉ phép';
    }

    public function getSubheading(): ?string
    {
        return 'Ghi một hoặc nhiều ngày. Ngày đã có phiếu được bỏ qua.';
    }

    /** Tạo qua service (nhiều ngày, nhật ký, tắt online nếu nghỉ hôm nay). */
    protected function handleRecordCreation(array $data): Model
    {
        $res = DriverLeaveService::create((int) $data['driver_id'], $data['from_date'], $data['to_date'] ?? null, $data['note'], auth()->id());
        if (! $res['ok']) {
            Notification::make()->danger()->title($res['message'])->send();
            $this->halt();
        }
        Notification::make()->success()->title($res['message'])->send();

        return $res['first'];
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function associateRecordWithTenant(Model $record, Model $tenant): Model
    {
        return $record;
    }
}
