<?php

namespace App\Filament\Resources\ShiftResource\Pages;

use App\Filament\Resources\ShiftResource;
use App\Services\ShiftService;
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
        return 'Tạo khung giờ mới cho khu vực hiện tại. Không được trùng giờ với ca đang kích hoạt.';
    }

    protected function afterCreate(): void
    {
        $s = $this->record;
        ShiftService::log($s->id, 'created', auth()->id(), $s->name.' '.substr($s->start_time, 0, 5).'–'.substr($s->end_time, 0, 5));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
