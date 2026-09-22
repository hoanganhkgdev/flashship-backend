<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

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
            Actions\Action::make('assignDriver')
                ->label('Gán tài xế')
                ->icon('heroicon-o-user-plus')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === 'pending')
                ->modalHeading(fn (): string => 'Gán tài xế cho đơn #'.$this->record->code)
                ->modalDescription('Đơn sẽ ngừng tìm tự động và chuyển thẳng vào danh sách đã nhận của tài xế.')
                ->form(fn (): array => OrderResource::manualAssignmentForm($this->record))
                ->action(function (array $data): void {
                    if (OrderResource::assignDriverManually($this->record, $data)) {
                        $this->redirect(OrderResource::getUrl('view', ['record' => $this->record]));
                    }
                }),
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
        // Trạng thái chỉ được đổi qua các action nghiệp vụ của OrderResource.
        // Không cho payload sửa form ghi thẳng status/cancel_reason rồi bỏ qua
        // hoàn voucher, điểm tài xế, ví, realtime và notification.
        unset($data['status'], $data['cancel_reason']);

        return $data;
    }
}
