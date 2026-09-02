<?php

namespace App\Filament\Resources\DriverDebtResource\Pages;

use App\Filament\Resources\DriverDebtResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDriverDebt extends ViewRecord
{
    protected static string $resource = DriverDebtResource::class;

    public function getTitle(): string
    {
        return 'Công nợ #'.$this->record->id;
    }

    public function getSubheading(): ?string
    {
        return ($this->record->driver?->name ?? 'Tài xế').' · '.($this->record->driver?->phone ?? '—');
    }

    protected function getHeaderActions(): array
    {
        return [Actions\EditAction::make()->label('Chỉnh sửa công nợ')->icon('heroicon-o-pencil-square')];
    }
}
