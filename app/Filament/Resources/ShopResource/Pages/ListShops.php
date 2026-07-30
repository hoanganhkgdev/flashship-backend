<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\ShopResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
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
        // Chọn khu vực nay dùng bộ chuyển tenant trên topbar — không cần tab
        // theo thành phố ở đây nữa.
        $cityId = \Filament\Facades\Filament::getTenant()?->id;

        $total  = User::where('user_type', 'shop')->where('city_id', $cityId)->count();
        $locked = User::where('user_type', 'shop')->where('city_id', $cityId)->where('status', 2)->count();

        $tabs = [
            'all' => Tab::make('Tất cả')->badge($total ?: null),
        ];

        if ($locked > 0) {
            $tabs['locked'] = Tab::make('Bị khoá')
                ->badge($locked)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 2));
        }

        return $tabs;
    }
}
