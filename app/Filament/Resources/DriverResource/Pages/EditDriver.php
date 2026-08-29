<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Modules\Order\Models\Order;

class EditDriver extends EditRecord
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [];
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
