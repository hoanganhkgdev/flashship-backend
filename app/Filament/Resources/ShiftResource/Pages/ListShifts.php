<?php

namespace App\Filament\Resources\ShiftResource\Pages;

use App\Filament\Resources\ShiftResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListShifts extends ListRecords
{
    protected static string $resource = ShiftResource::class;

    public function getHeading(): string
    {
        return 'Ca làm việc';
    }

    public function getSubheading(): ?string
    {
        return 'Thiết lập khung giờ hoạt động và danh sách ca tài xế có thể đăng ký.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Thêm ca')->icon('heroicon-o-plus'),
        ];
    }
}
