<?php

namespace App\Filament\Resources\DriverRatingResource\Pages;

use App\Filament\Resources\DriverRatingResource;
use App\Filament\Resources\DriverRatingResource\Widgets\RatingStatsWidget;
use App\Services\DriverRatingService;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListDriverRatings extends ListRecords
{
    protected static string $resource = DriverRatingResource::class;

    public function getHeading(): string
    {
        return 'Đánh giá tài xế';
    }

    public function getSubheading(): ?string
    {
        return 'Phản hồi của khách và shop sau đơn. Đánh giá hiện không ảnh hưởng điểm tài xế.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [RatingStatsWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }

    public function getTabs(): array
    {
        $low = DriverRatingService::LOW;
        $visible = fn ($query) => $query->where('rating_hidden', false);
        // Đếm theo đúng truy vấn của bảng (đã lọc khu vực đang chọn).
        $count = fn (callable $scope) => $scope(DriverRatingResource::getEloquentQuery())->count() ?: null;

        $pending = fn ($query) => $visible($query)->where('driver_rating', '<=', $low)->whereNull('rating_handled_at');
        $lowAll = fn ($query) => $visible($query)->where('driver_rating', '<=', $low);
        $mid = fn ($query) => $visible($query)->where('driver_rating', 3);
        $high = fn ($query) => $visible($query)->where('driver_rating', '>=', 4);
        $handled = fn ($query) => $visible($query)->whereNotNull('rating_handled_at');
        $hidden = fn ($query) => $query->where('rating_hidden', true);

        return [
            'pending' => Tab::make('Cần xử lý')->modifyQueryUsing($pending)->badge($count($pending))->badgeColor('danger'),
            'all' => Tab::make('Tất cả')->modifyQueryUsing($visible)->badge($count($visible)),
            'low' => Tab::make('1–2 sao')->modifyQueryUsing($lowAll),
            'mid' => Tab::make('3 sao')->modifyQueryUsing($mid),
            'high' => Tab::make('4–5 sao')->modifyQueryUsing($high),
            'handled' => Tab::make('Đã xử lý')->modifyQueryUsing($handled),
            'hidden' => Tab::make('Đã ẩn')->modifyQueryUsing($hidden)->badge($count($hidden)),
        ];
    }
}
