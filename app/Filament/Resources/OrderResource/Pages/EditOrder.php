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

    public function getTitle(): string
    {
        return 'Chỉnh sửa đơn #'.$this->record->code;
    }

    public function getSubheading(): ?string
    {
        return 'Cập nhật thông tin giao nhận, chi phí và trạng thái đơn hàng.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()
                ->label('Xem chi tiết')
                ->icon('heroicon-o-eye'),
            Actions\DeleteAction::make()
                ->label('Xoá đơn'),
        ];
    }

    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()
            ->label('Lưu thay đổi')
            ->icon('heroicon-o-check');
    }

    protected function getCancelFormAction(): Actions\Action
    {
        return parent::getCancelFormAction()->label('Huỷ thay đổi');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Chống race condition: form mở lúc đơn pending, tài xế nhận (→ assigned)
        // trước khi tổng đài bấm Save → form ghi đè status = pending, đá tài xế ra.
        // Giải pháp: reload status mới nhất từ DB, nếu đơn đang active thì giữ nguyên.
        $fresh = Order::find($this->record->id);

        if ($fresh && in_array($fresh->status, ['assigned', 'processing'])) {
            if ($data['status'] === 'pending') {
                // Tổng đài chưa đổi status thủ công — giữ trạng thái DB hiện tại
                $data['status'] = $fresh->status;
                unset($data['cancel_reason']);
            }
        }

        // Chặn đổi thẳng status sang assigned/processing mà không qua
        // "Gán tài xế" — form này không có ô chọn tài xế, đổi tay kiểu này để lại
        // đơn "ma" (status active nhưng delivery_man_id NULL) mà dispatch tự động
        // lẫn cảnh báo "không tìm được tài xế" đều bỏ qua vì không còn pending.
        if (in_array($data['status'], ['assigned', 'processing']) && ! $fresh?->delivery_man_id) {
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
