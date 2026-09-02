<?php

namespace App\Filament\Resources\DriverLeaveRequestResource\Pages;

use App\Filament\Resources\DriverLeaveRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDriverLeaveRequests extends ListRecords
{
    protected static string $resource = DriverLeaveRequestResource::class;

    public function getHeading(): string
    {
        return 'Lịch nghỉ tài xế';
    }

    public function getSubheading(): ?string
    {
        return 'Ghi nhận ngày nghỉ hợp lệ để loại trừ khỏi quy tắc chấm điểm có mặt.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Ghi nhận nghỉ phép')->icon('heroicon-o-plus'),
        ];
    }
}
