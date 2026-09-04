<?php

namespace App\Filament\Resources\ShopPricingResource\Pages;

use App\Filament\Resources\ShopPricingResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Shop\Models\ShopPricingConfig;

class ListShopPricings extends ListRecords
{
    protected static string $resource = ShopPricingResource::class;

    public function getHeading(): string
    {
        return 'Bảng giá cửa hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Cấu hình phí giao theo loại hàng, quãng đường và trọng lượng.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Thêm bảng giá')->icon('heroicon-o-plus'),
        ];
    }

    public function getTabs(): array
    {
        $query = fn () => ShopPricingConfig::query()->where(
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
