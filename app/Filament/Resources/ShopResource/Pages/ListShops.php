<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\ShopResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\City;
use Modules\Core\Models\User;

class ListShops extends ListRecords
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Thêm cửa hàng'),
        ];
    }

    public function getTabs(): array
    {
        $total = User::where('user_type', 'shop')->count();

        $tabs = [
            'all' => Tab::make('Tất cả')->badge($total ?: null),
        ];

        $cities = City::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        foreach ($cities as $city) {
            $count = User::where('user_type', 'shop')->where('city_id', $city->id)->count();
            if ($count === 0) continue;
            $tabs['city_' . $city->id] = Tab::make($city->name)
                ->badge($count)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('city_id', $city->id));
        }

        $active  = User::where('user_type', 'shop')->where('status', 1)->count();
        $locked  = User::where('user_type', 'shop')->where('status', 2)->count();

        if ($locked > 0) {
            $tabs['locked'] = Tab::make('Bị khoá')
                ->badge($locked)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 2));
        }

        return $tabs;
    }
}
