<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\ShopResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\User;

class ListShops extends ListRecords
{
    protected static string $resource = ShopResource::class;

    public function getHeading(): string
    {
        return 'Quản lý cửa hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Theo dõi tài khoản, hoạt động đơn hàng và thông tin liên hệ của đối tác.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Thêm cửa hàng')->icon('heroicon-o-plus'),
        ];
    }

    public function getTabs(): array
    {
        // Chọn khu vực nay dùng bộ chuyển tenant trên topbar — không cần tab
        // theo thành phố ở đây nữa.
        $cityId = Filament::getTenant()?->id;

        $total = User::where('user_type', 'shop')->where('city_id', $cityId)->count();
        $active = User::where('user_type', 'shop')->where('city_id', $cityId)->where('status', 1)->count();
        $locked = User::where('user_type', 'shop')->where('city_id', $cityId)->where('status', 2)->count();

        $tabs = [
            'all' => Tab::make('Tất cả')->badge($total ?: null),
            'active' => Tab::make('Hoạt động')
                ->icon('heroicon-m-check-circle')
                ->badge($active ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 1)),
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
