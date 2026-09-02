<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCity extends ViewRecord
{
    protected static string $resource = CityResource::class;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Tổng quan cấu hình và quy mô hoạt động của khu vực.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('Chỉnh sửa khu vực')->icon('heroicon-o-pencil-square'),
        ];
    }
}
