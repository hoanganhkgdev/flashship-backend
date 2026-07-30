<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
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
        // Chọn khu vực nay dùng bộ chuyển tenant trên topbar — không cần tab
        // theo thành phố ở đây nữa.
        $cityId = \Filament\Facades\Filament::getTenant()?->id;
        $totalCount = User::where('user_type', 'driver')->where('city_id', $cityId)->count();

        return [
            'all' => Tab::make('Tất cả')->badge($totalCount ?: null),
        ];
    }
}
