<?php

namespace App\Filament\Resources\ServiceTypeResource\Pages;

use App\Filament\Resources\ServiceTypeResource;
use App\Services\CatalogService;
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

    private const TRACKED = ['label' => 'Tên', 'sort_order' => 'Thứ tự', 'is_active' => 'Hiển thị', 'icon_url' => 'Icon'];

    private array $before = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->before = $this->record->only(array_keys(self::TRACKED));

        return $data;
    }

    protected function afterSave(): void
    {
        CatalogService::logChanges('service_type', $this->record->id, $this->before, $this->record->refresh()->only(array_keys(self::TRACKED)), self::TRACKED, auth()->id());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
