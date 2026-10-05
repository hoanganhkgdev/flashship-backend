<?php

namespace App\Filament\Resources\DriverScoreResource\Pages;

use App\Filament\Resources\DriverScoreResource;
use App\Filament\Resources\DriverScoreResource\Widgets\DriverScoreStatsWidget;
use Filament\Facades\Filament;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Modules\Core\Models\User;
use Modules\Driver\Services\DriverScoreService;

class ListDriverScores extends ListRecords
{
    protected static string $resource = DriverScoreResource::class;

    public function getHeading(): string
    {
        return 'Điểm tài xế';
    }

    public function getSubheading(): ?string
    {
        return 'Xếp loại, biến động điểm và kết quả thưởng/phạt dự kiến của tuần này.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [DriverScoreStatsWidget::class];
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
        $bonus = DriverScoreService::weeklyBonusScore($cityId);
        $penalty = DriverScoreService::weeklyPenaltyScore($cityId);

        $count = fn (?callable $scope = null) => User::where('user_type', 'driver')->where('city_id', $cityId)
            ->when($scope, fn ($q) => $scope($q))->count() ?: null;
        $active = fn ($query) => $query->where('status', 1);

        return [
            'active' => Tab::make('Đang hoạt động')
                ->modifyQueryUsing($active)
                ->badge($count($active))->badgeColor('success'),
            'excellent' => Tab::make('Xuất sắc')
                ->modifyQueryUsing(fn ($query) => $active($query)->where('driver_score', '>=', $bonus))
                ->badge($count(fn ($q) => $active($q)->where('driver_score', '>=', $bonus))),
            'poor' => Tab::make('Cần cải thiện')
                ->modifyQueryUsing(fn ($query) => $active($query)->where('driver_score', '<=', $penalty))
                ->badge($count(fn ($q) => $active($q)->where('driver_score', '<=', $penalty)))->badgeColor('danger'),
            'all' => Tab::make('Tất cả')
                ->badge($count()),
        ];
    }
}
