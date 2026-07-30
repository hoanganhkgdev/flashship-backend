<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Modules\Core\Models\User;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

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
        $totalCount = User::where('user_type', 'customer')->where('city_id', $cityId)->count();

        return [
            'all' => Tab::make('Tất cả')->badge($totalCount ?: null),
        ];
    }
}
