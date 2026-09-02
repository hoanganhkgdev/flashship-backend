<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Modules\Order\Models\Order;

class EditDriver extends EditRecord
{
    protected static string $resource = DriverResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Cập nhật thông tin cá nhân, phương tiện và ca làm việc.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()->label('Xem hồ sơ')->icon('heroicon-o-eye'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['city_id']) && (int) $data['city_id'] !== (int) $this->record->city_id) {
            $busy = $this->record->is_online || Order::where('delivery_man_id', $this->record->id)
                ->whereIn('status', ['assigned', 'processing'])->exists();
            if ($busy) {
                throw ValidationException::withMessages(['data.city_id' => 'Không thể đổi khu vực khi tài xế đang online hoặc còn đơn chưa hoàn thành.']);
            }
        }

        return $data;
    }
}
