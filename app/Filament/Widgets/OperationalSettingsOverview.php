<?php

namespace App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Core\Services\OperationalSettings;

/** Tóm tắt các chỉ số vận hành quan trọng ở đầu trang Cấu hình vận hành. */
class OperationalSettingsOverview extends StatsOverviewWidget
{
    // Chỉ dùng trong OperationalSettingsPage, không tự hiện trên Dashboard.
    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = null;

    /** @var array<string, string> */
    protected $listeners = ['operational-settings-saved' => '$refresh'];

    protected function getStats(): array
    {
        $money = fn (int $amount): string => number_format($amount, 0, ',', '.').'đ';
        // Khu vực đang chọn trên thanh trên cùng.
        $cityId = Filament::getTenant()?->getKey();

        return [
            Stat::make('Phạm vi phát đơn', rtrim(rtrim(number_format(OperationalSettings::dispatchMaxRoadDistanceKm($cityId), 1, ',', '.'), '0'), ',').' km')
                ->description('Tối đa '.OperationalSettings::maxActiveOrdersPerDriver($cityId).' đơn active / tài xế')
                ->descriptionIcon('heroicon-m-map-pin')
                ->color('primary'),
            Stat::make('Thời gian offer', OperationalSettings::offerOpenSeconds($cityId).'s + '.OperationalSettings::offerDecisionSeconds($cityId).'s')
                ->description('Mở app + quyết định · tìm '.OperationalSettings::dispatchTimeoutMinutes($cityId).' phút')
                ->descriptionIcon('heroicon-m-clock')
                ->color('info'),
            Stat::make('Chốt tuần (thưởng / phạt)', OperationalSettings::weeklyBonusScore($cityId).' / '.OperationalSettings::weeklyPenaltyScore($cityId).' điểm')
                ->description('+'.$money(OperationalSettings::weeklyBonusAmount($cityId)).' / −'.$money(OperationalSettings::weeklyPenaltyAmount($cityId)))
                ->descriptionIcon('heroicon-m-trophy')
                ->color('success'),
            Stat::make('Thưởng trời mưa', $money(OperationalSettings::rainBonusAmount($cityId)))
                ->description('Mỗi đơn · tự tắt sau '.OperationalSettings::rainModeAutoOffHours($cityId).' giờ')
                ->descriptionIcon('heroicon-m-cloud')
                ->color('warning'),
        ];
    }
}
