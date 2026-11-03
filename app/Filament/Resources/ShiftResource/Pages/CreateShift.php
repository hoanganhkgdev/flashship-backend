<?php

namespace App\Filament\Resources\ShiftResource\Pages;

use App\Filament\Resources\ShiftResource;
use App\Services\ShiftService;
use Filament\Facades\Filament;
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
        return 'Tạo khung giờ mới cho khu vực hiện tại. Các ca được phép chồng giờ nhau, nhưng một tài xế không đăng ký được hai ca chồng giờ.';
    }

    /** Mã ca chỉ là định danh nội bộ: tự sinh từ tên, không bắt admin nhập. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['code'] = ShiftService::uniqueCode((int) Filament::getTenant()?->id, (string) $data['name']);

        return $data;
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
