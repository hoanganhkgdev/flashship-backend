<?php

namespace App\Filament\Resources\ShiftResource\Pages;

use App\Filament\Resources\ShiftResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShift extends CreateRecord
{
    protected static string $resource = ShiftResource::class;

    public function getTitle(): string
    {
        return 'Thêm ca làm việc';
    }

    public function getSubheading(): ?string
    {
        return 'Tạo khung giờ mới cho khu vực hiện tại.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
