<?php

namespace App\Filament\Resources\DriverDebtResource\Pages;

use App\Filament\Resources\DriverDebtResource;
use App\Services\DriverDebtService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Modules\Driver\Models\DriverDebt;

class CreateDriverDebt extends CreateRecord
{
    protected static string $resource = DriverDebtResource::class;

    public function getTitle(): string
    {
        return 'Tạo công nợ';
    }

    public function getSubheading(): ?string
    {
        return 'Ghi nhận khoản phải thu theo tài xế. Việc tạo được lưu vào nhật ký.';
    }

    /** Tạo qua service để có nhật ký; không dùng cơ chế tự gán tenant (DriverDebt không có city_id). */
    protected function handleRecordCreation(array $data): Model
    {
        $res = DriverDebtService::create((int) $data['driver_id'], $data['debt_type'], (float) $data['amount_due'], $data['note'], auth()->id());
        if (! $res['ok']) {
            Notification::make()->danger()->title($res['message'])->send();
            $this->halt();
        }

        return DriverDebt::where('driver_id', $data['driver_id'])->latest('id')->firstOrFail();
    }

    protected function associateRecordWithTenant(Model $record, Model $tenant): Model
    {
        return $record;
    }
}
