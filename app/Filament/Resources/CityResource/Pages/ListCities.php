<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\City;

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

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tất cả')->badge(City::count() ?: null),
            'active' => Tab::make('Đang hoạt động')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge(City::where('is_active', true)->count() ?: null)
                ->badgeColor('success'),
            'inactive' => Tab::make('Đã tắt')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge(City::where('is_active', false)->count() ?: null)
                ->badgeColor('gray'),
            'rain' => Tab::make('Đang mưa')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_rain_mode', true))
                ->badge(City::where('is_rain_mode', true)->count() ?: null)
                ->badgeColor('info'),
        ];
    }
}
