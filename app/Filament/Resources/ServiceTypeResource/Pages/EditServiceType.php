<?php

namespace App\Filament\Resources\ServiceTypeResource\Pages;

use App\Filament\Resources\ServiceTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

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
            Actions\DeleteAction::make()->label('Xoá dịch vụ'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
