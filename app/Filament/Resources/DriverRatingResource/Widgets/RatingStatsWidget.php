<?php

namespace App\Filament\Resources\DriverRatingResource\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Order\Models\Order;

class RatingStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $base = Order::whereNotNull('driver_rating');

        $total   = $base->count();
        $average = $total > 0 ? round($base->avg('driver_rating'), 1) : 0;
        $low     = (clone $base)->where('driver_rating', '<=', 2)->count();
        $perfect = (clone $base)->where('driver_rating', 5)->count();

        $star5 = (clone $base)->where('driver_rating', 5)->count();
        $star4 = (clone $base)->where('driver_rating', 4)->count();
        $star3 = (clone $base)->where('driver_rating', 3)->count();
        $star2 = (clone $base)->where('driver_rating', 2)->count();
        $star1 = (clone $base)->where('driver_rating', 1)->count();

        $distribution = $total > 0
            ? "⭐×5: {$star5} | ⭐×4: {$star4} | ⭐×3: {$star3} | ⭐×2: {$star2} | ⭐×1: {$star1}"
            : 'Chưa có dữ liệu';

        return [
            Stat::make('Tổng đánh giá', number_format($total))
                ->description($distribution)
                ->descriptionIcon('heroicon-m-star')
                ->color('primary'),

            Stat::make('Điểm trung bình', $average . ' / 5')
                ->description($perfect . ' đánh giá 5 sao')
                ->descriptionIcon('heroicon-m-trophy')
                ->color($average >= 4.5 ? 'success' : ($average >= 3.5 ? 'warning' : 'danger')),

            Stat::make('Đánh giá thấp (≤ 2★)', number_format($low))
                ->description($total > 0 ? round($low / $total * 100, 1) . '% tổng đánh giá' : '—')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($low === 0 ? 'success' : 'danger'),
        ];
    }
}
