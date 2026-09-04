<?php

namespace App\Filament\Resources\PricingResource\Pages;

use App\Filament\Resources\PricingResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Pricing\Models\PricingConfig;

class ListPricingConfigs extends ListRecords
{
    protected static string $resource = PricingResource::class;

    public function getHeading(): string
    {
        return 'Bảng giá dịch vụ';
    }

    public function getSubheading(): ?string
    {
        return 'Cấu hình đơn giá mặc định hoặc bảng giá riêng theo từng khu vực.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Thêm bảng giá')->icon('heroicon-o-plus')];
    }

    public function getTabs(): array
    {
        $query = fn () => PricingConfig::query()->where(
            fn (Builder $query) => $query
                ->where('city_id', Filament::getTenant()?->id)
                ->orWhereNull('city_id')
        );

        return [
            'all' => Tab::make('Tất cả')->badge($query()->count() ?: null),
            'local' => Tab::make('Giá khu vực')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('city_id', Filament::getTenant()?->id))
                ->badge($query()->where('city_id', Filament::getTenant()?->id)->count() ?: null)
                ->badgeColor('info'),
            'global' => Tab::make('Giá mặc định')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('city_id'))
                ->badge($query()->whereNull('city_id')->count() ?: null)
                ->badgeColor('gray'),
            'inactive' => Tab::make('Đã tắt')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge($query()->where('is_active', false)->count() ?: null)
                ->badgeColor('danger'),
        ];
    }
}
