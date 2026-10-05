<?php

namespace App\Filament\Resources\SupportConfigResource\Pages;

use App\Filament\Resources\SupportConfigResource;
use App\Filament\Resources\SupportConfigResource\Widgets\SupportOverviewWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Modules\Admin\Models\SupportConfig;

class ListSupportConfigs extends ListRecords
{
    protected static string $resource = SupportConfigResource::class;

    public function getHeading(): string
    {
        return 'Kênh hỗ trợ';
    }

    public function getSubheading(): ?string
    {
        return 'Hotline, Zalo, mạng xã hội hiển thị trong app khách, tài xế, cửa hàng và trang hỗ trợ trên web. Kéo để đổi thứ tự.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Thêm kênh hỗ trợ')->icon('heroicon-o-plus')];
    }

    protected function getHeaderWidgets(): array
    {
        return [SupportOverviewWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    public function getTabs(): array
    {
        $for = fn (string $aud) => fn ($q) => $q->whereIn('audience', ['all', $aud])->where('is_active', true);
        $base = fn () => SupportConfigResource::scopeEloquentQueryToTenant(SupportConfig::query(), \Filament\Facades\Filament::getTenant());
        $tab = fn (string $label, callable $scope, ?string $color = null) => Tab::make($label)
            ->modifyQueryUsing(fn ($query) => $scope($query))->badge($scope($base())->count() ?: null)->badgeColor($color);

        return [
            'all' => $tab('Tất cả', fn ($q) => $q),
            'customer' => $tab('Khách hàng', $for('customer'), 'success'),
            'driver' => $tab('Tài xế', $for('driver'), 'info'),
            'shop' => $tab('Cửa hàng', $for('shop'), 'warning'),
            'hidden' => $tab('Đã ẩn', fn ($q) => $q->where('is_active', false), 'gray'),
        ];
    }
}
