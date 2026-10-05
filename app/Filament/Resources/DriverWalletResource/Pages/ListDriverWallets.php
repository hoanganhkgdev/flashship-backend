<?php

namespace App\Filament\Resources\DriverWalletResource\Pages;

use App\Filament\Resources\DriverWalletResource;
use App\Filament\Resources\DriverWalletResource\Widgets\DriverWalletStatsWidget;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Models\DriverWallet;

class ListDriverWallets extends ListRecords
{
    protected static string $resource = DriverWalletResource::class;

    public function getHeading(): string
    {
        return 'Ví tài xế';
    }

    public function getSubheading(): ?string
    {
        return 'Số dư, dòng tiền và lịch sử điều chỉnh ví của tài xế trong khu vực.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [DriverWalletStatsWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    public function getTabs(): array
    {
        $cityId = Filament::getTenant()?->id;
        $low = DriverWalletResource::lowThreshold();
        $status = fn ($query, int $s) => $query->whereHas('driver', fn ($q) => $q->where('status', $s));

        $active = fn ($query) => $status($query, 1);
        $lowTab = fn ($query) => $status($query, 1)->where('balance', '<', $low);
        $pending = fn ($query) => $query->whereExists(fn ($q) => $q->from('withdraw_requests')
            ->select(DB::raw(1))->whereColumn('withdraw_requests.driver_id', 'driver_wallets.driver_id')->where('status', 'pending'));
        $locked = fn ($query) => $status($query, 2)->where('balance', '>', 0);

        $count = fn (callable $scope) => $scope(DriverWallet::query()->whereHas('driver', fn ($q) => $q->where('city_id', $cityId)))->count() ?: null;

        return [
            'active' => Tab::make('Đang hoạt động')->modifyQueryUsing($active)->badge($count($active))->badgeColor('success'),
            'low' => Tab::make('Số dư thấp')->modifyQueryUsing($lowTab)->badge($count($lowTab))->badgeColor('warning'),
            'pending' => Tab::make('Có chờ rút')->modifyQueryUsing($pending)->badge($count($pending)),
            'locked' => Tab::make('Đã khóa còn tiền')->modifyQueryUsing($locked)->badge($count($locked))->badgeColor('danger'),
            'all' => Tab::make('Tất cả'),
        ];
    }
}
