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
        return 'Tình trạng từng khu vực: đơn, tài xế, giá, ca làm việc và các điểm cần rà soát.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Thêm khu vực')->icon('heroicon-o-plus')];
    }

    protected function getHeaderWidgets(): array
    {
        return [\App\Filament\Resources\CityResource\Widgets\CityOverviewWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
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
            'attention' => Tab::make('Cần rà soát')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('id', self::attentionIds()))
                ->badge(count(self::attentionIds()) ?: null)
                ->badgeColor('warning'),
            'rain' => Tab::make('Đang mưa')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_rain_mode', true))
                ->badge(City::where('is_rain_mode', true)->count() ?: null)
                ->badgeColor('info'),
        ];
    }

    /** Khu vực có cờ cảnh báo thật sự (bỏ qua cờ chỉ mang tính thông tin). */
    private static function attentionIds(): array
    {
        static $ids = null;

        return $ids ??= \App\Services\CatalogService::health()
            ->filter(fn ($h) => collect($h['flags'])->contains(fn ($f) => in_array($f['level'], ['warning', 'danger'], true)))
            ->keys()->all();
    }
}
