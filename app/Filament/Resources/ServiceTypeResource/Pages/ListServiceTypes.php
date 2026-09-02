<?php

namespace App\Filament\Resources\ServiceTypeResource\Pages;

use App\Filament\Resources\ServiceTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListServiceTypes extends ListRecords
{
    protected static string $resource = ServiceTypeResource::class;

    public function getHeading(): string
    {
        return 'Loại dịch vụ';
    }

    public function getSubheading(): ?string
    {
        return 'Quản lý danh mục và thứ tự hiển thị dịch vụ trên ứng dụng.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Thêm dịch vụ')->icon('heroicon-o-plus'),
        ];
    }
}
