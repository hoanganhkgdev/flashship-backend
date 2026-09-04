<?php

namespace App\Filament\Resources\DriverLeaveRequestResource\Pages;

use App\Filament\Resources\DriverLeaveRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Modules\Driver\Models\DriverLeaveRequest;

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

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (DriverLeaveRequest::where('driver_id', $data['driver_id'])
            ->whereDate('leave_date', $data['leave_date'])
            ->where('id', '<>', $this->record->getKey())
            ->exists()) {
            throw ValidationException::withMessages(['data.leave_date' => 'Tài xế đã được ghi nhận nghỉ trong ngày này.']);
        }

        return $data;
    }
}
