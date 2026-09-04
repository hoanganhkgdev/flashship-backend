<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\User;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getHeading(): string
    {
        return 'Quản lý khách hàng';
    }

    public function getSubheading(): ?string
    {
        return 'Tra cứu tài khoản, lịch sử đơn hàng và trạng thái truy cập của khách hàng.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Thêm khách hàng')->icon('heroicon-o-user-plus'),
        ];
    }

    public function getTabs(): array
    {
        // Chọn khu vực nay dùng bộ chuyển tenant trên topbar — không cần tab
        // theo thành phố ở đây nữa.
        $cityId = Filament::getTenant()?->id;
        $baseQuery = User::where('user_type', 'customer')->where('city_id', $cityId);
        $totalCount = (clone $baseQuery)->count();
        $activeCount = (clone $baseQuery)->where('status', 1)->count();
        $pendingCount = (clone $baseQuery)->where('status', 0)->count();
        $lockedCount = (clone $baseQuery)->where('status', 2)->count();

        return [
            'all' => Tab::make('Tất cả')->badge($totalCount ?: null),
            'active' => Tab::make('Hoạt động')
                ->icon('heroicon-m-check-circle')
                ->badge($activeCount ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 1)),
            'pending' => Tab::make('Chờ duyệt')
                ->icon('heroicon-m-clock')
                ->badge($pendingCount ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 0)),
            'locked' => Tab::make('Bị khoá')
                ->icon('heroicon-m-lock-closed')
                ->badge($lockedCount ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 2)),
        ];
    }
}
