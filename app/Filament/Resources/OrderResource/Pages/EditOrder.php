<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Notifications\Notification;
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

        // Chặn đổi thẳng status sang assigned/processing/on_the_way mà không qua
        // "Gán tài xế" — form này không có ô chọn tài xế, đổi tay kiểu này để lại
        // đơn "ma" (status active nhưng delivery_man_id NULL) mà dispatch tự động
        // lẫn cảnh báo "không tìm được tài xế" đều bỏ qua vì không còn pending.
        if (in_array($data['status'], ['assigned', 'processing', 'on_the_way']) && !$fresh?->delivery_man_id) {
            Notification::make()
                ->title('Không thể lưu')
                ->body('Đơn chưa có tài xế nào được gán. Dùng chức năng "Gán tài xế" thay vì đổi trạng thái trực tiếp.')
                ->danger()
                ->send();
            $this->halt();
        }

        return $data;
    }
}
