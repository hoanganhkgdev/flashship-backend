<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Order\Models\Order;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected static string $view = 'filament.resources.order-resource.pages.edit-order';

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Chống race condition: form mở lúc đơn pending, tài xế nhận (→ assigned)
        // trước khi tổng đài bấm Save → form ghi đè status = pending, đá tài xế ra.
        // Giải pháp: reload status mới nhất từ DB, nếu đơn đang active thì giữ nguyên.
        $fresh = Order::find($this->record->id);

        if ($fresh && in_array($fresh->status, ['assigned', 'processing', 'on_the_way'])) {
            if ($data['status'] === 'pending') {
                // Tổng đài chưa đổi status thủ công — giữ trạng thái DB hiện tại
                $data['status'] = $fresh->status;
                unset($data['cancel_reason']);
            }
        }

        return $data;
    }
}
