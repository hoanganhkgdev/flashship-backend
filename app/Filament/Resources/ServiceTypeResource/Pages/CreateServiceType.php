<?php

namespace App\Filament\Resources\ServiceTypeResource\Pages;

use App\Filament\Resources\ServiceTypeResource;
use App\Services\CatalogService;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceType extends CreateRecord
{
    protected static string $resource = ServiceTypeResource::class;

    public function getTitle(): string
    {
        return 'Thêm loại dịch vụ';
    }

    public function getSubheading(): ?string
    {
        return 'Tạo dịch vụ mới và thiết lập cách hiển thị trên ứng dụng.';
    }

    protected function afterCreate(): void
    {
        CatalogService::log('service_type', $this->record->id, 'created', auth()->id(), $this->record->label.' ('.$this->record->key.')');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
