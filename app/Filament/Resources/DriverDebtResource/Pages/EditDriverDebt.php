<?php

namespace App\Filament\Resources\DriverDebtResource\Pages;

use App\Filament\Resources\DriverDebtResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDriverDebt extends EditRecord
{
    protected static string $resource = DriverDebtResource::class;

    public function getTitle(): string
    {
        return 'Chỉnh sửa công nợ #'.$this->record->id;
    }

    public function getSubheading(): ?string
    {
        return 'Cập nhật kỳ đối soát và thông tin thanh toán.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()->label('Xem chi tiết')->icon('heroicon-o-eye'),
            Actions\DeleteAction::make()->label('Xoá công nợ'),
        ];
    }
}
