<?php

namespace App\Filament\Resources\ServiceTypeResource\Pages;

use App\Filament\Resources\ServiceTypeResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Models\ServiceType;

class EditServiceType extends EditRecord
{
    protected static string $resource = ServiceTypeResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa '.$this->record->label;
    }

    public function getSubheading(): ?string
    {
        return 'Cập nhật hình ảnh, thứ tự và trạng thái dịch vụ.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Xoá dịch vụ')
                ->before(function (ServiceType $record, Actions\DeleteAction $action) {
                    $orderCount = $record->orders()->count();
                    $pricingCount = $record->pricingConfigs()->count();
                    if ($orderCount > 0 || $pricingCount > 0) {
                        Notification::make()->danger()
                            ->title('Không thể xóa dịch vụ này')
                            ->body("Dịch vụ đang có {$orderCount} đơn hàng và {$pricingCount} cấu hình giá. Hãy tắt hiển thị nếu không còn sử dụng.")
                            ->send();
                        $action->halt();
                    }
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
