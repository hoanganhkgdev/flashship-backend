<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use App\Filament\Resources\DriverResource\Widgets\DriverStatsWidget;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Modules\Core\Models\User;

class ListDrivers extends ListRecords
{
    protected static string $resource = DriverResource::class;

    public function getHeading(): string
    {
        return 'Quản lý tài xế';
    }

    public function getSubheading(): ?string
    {
        return 'Tài xế tự đăng ký trên app và chờ duyệt hồ sơ. Duyệt, khóa và mở khóa đều được ghi lịch sử.';
    }

    protected function getHeaderWidgets(): array
    {
        return [DriverStatsWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    public function getTabs(): array
    {
        // Khu vực chọn ở bộ chuyển trên topbar, nên không cần tab theo thành phố.
        $count = fn (?int $status, bool $low = false) => User::where('user_type', 'driver')
            ->where('city_id', Filament::getTenant()?->id)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($low, fn ($q) => $q->where('driver_score', '<', DriverResource::LOW_SCORE))
            ->count();

        return [
            'all' => Tab::make('Tất cả')->badge($count(null) ?: null),
            'pending' => Tab::make('Chờ duyệt')
                ->icon('heroicon-m-clock')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 0))
                ->badge($count(0) ?: null)
                ->badgeColor('warning'),
            'active' => Tab::make('Hoạt động')
                ->icon('heroicon-m-check-circle')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 1))
                ->badge($count(1) ?: null)
                ->badgeColor('success'),
            'locked' => Tab::make('Bị khóa')
                ->icon('heroicon-m-lock-closed')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 2))
                ->badge($count(2) ?: null)
                ->badgeColor('danger'),
            'low' => Tab::make('Điểm thấp')
                ->icon('heroicon-m-arrow-trending-down')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 1)->where('users.driver_score', '<', DriverResource::LOW_SCORE))
                ->badge(User::where('user_type', 'driver')->where('city_id', Filament::getTenant()?->id)->where('status', 1)->where('driver_score', '<', DriverResource::LOW_SCORE)->count() ?: null)
                ->badgeColor('danger'),
        ];
    }
}
