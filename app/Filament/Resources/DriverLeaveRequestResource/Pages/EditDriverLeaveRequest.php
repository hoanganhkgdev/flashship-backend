<?php

namespace App\Filament\Resources\DriverLeaveRequestResource\Pages;

use App\Filament\Resources\DriverLeaveRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDriverLeaveRequest extends EditRecord
{
    protected static string $resource = DriverLeaveRequestResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa lịch nghỉ';
    }

    public function getSubheading(): ?string
    {
        return ($this->record->driver?->name ?? 'Tài xế').' · '.$this->record->leave_date?->format('d/m/Y');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Xoá lịch nghỉ'),
        ];
    }
}
