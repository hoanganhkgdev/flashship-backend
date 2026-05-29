<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\City;
use Modules\Core\Models\User;

class ListDrivers extends ListRecords
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $totalCount = User::where('user_type', 'driver')->count();

        $tabs = [
            'all' => Tab::make('Tất cả')->badge($totalCount ?: null),
        ];

        $cities = City::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        foreach ($cities as $city) {
            $count = User::where('user_type', 'driver')->where('city_id', $city->id)->count();
            $tabs['city_' . $city->id] = Tab::make($city->name)
                ->badge($count ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('city_id', $city->id));
        }

        $nullCount = User::where('user_type', 'driver')->whereNull('city_id')->count();
        if ($nullCount > 0) {
            $tabs['no_city'] = Tab::make('Chưa có khu vực')
                ->badge($nullCount)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('city_id'));
        }

        return $tabs;
    }
}
