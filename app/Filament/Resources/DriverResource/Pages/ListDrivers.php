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
        // theo thành phố ở đây nữa. Tách tab theo status để bấm chuyển nhanh
        // giữa các trạng thái thay vì phải dùng bộ lọc.
        $cityId = \Filament\Facades\Filament::getTenant()?->id;

        $countByStatus = fn (?int $status) => User::where('user_type', 'driver')
            ->where('city_id', $cityId)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->count();

        return [
            'all'     => Tab::make('Tất cả')
                ->badge($countByStatus(null) ?: null),
            'pending' => Tab::make('Chờ duyệt')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 0))
                ->badge($countByStatus(0) ?: null)
                ->badgeColor('warning'),
            'active'  => Tab::make('Hoạt động')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 1))
                ->badge($countByStatus(1) ?: null)
                ->badgeColor('success'),
            'locked'  => Tab::make('Bị khóa')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 2))
                ->badge($countByStatus(2) ?: null)
                ->badgeColor('danger'),
        ];
    }
}
