<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCities extends ListRecords
{
    protected static string $resource = CityResource::class;

    public function getHeading(): string
    {
        return 'Khu vực hoạt động';
    }

    public function getSubheading(): ?string
    {
        return 'Quản lý phạm vi phục vụ, phí duy trì và toạ độ trung tâm của từng khu vực.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Thêm khu vực')->icon('heroicon-o-plus')];
    }
}
