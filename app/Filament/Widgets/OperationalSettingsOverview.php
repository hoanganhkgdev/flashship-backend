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
            Stat::make('Đơn active tối đa', OperationalSettings::MAX_ACTIVE_ORDERS_PER_DRIVER.' đơn')
                ->description('Cố định · mỗi tài xế giữ cùng lúc')
                ->descriptionIcon('heroicon-m-map-pin')
                ->color('primary'),
            Stat::make('Thưởng trời mưa', $money(OperationalSettings::rainBonusAmount($cityId)))
                ->description('Mỗi đơn · tự tắt sau '.OperationalSettings::rainModeAutoOffHours($cityId).' giờ')
                ->descriptionIcon('heroicon-m-cloud')
                ->color('warning'),
        ];
    }
}
